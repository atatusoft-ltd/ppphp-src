#!/usr/bin/env python3
"""Build the source-pinned candidate without any upstream prebuilt-library inputs."""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import tarfile
import time
from pathlib import Path

import native
import prepare

HERE = Path(__file__).resolve().parent


def replace_block(source: str, start: str, end: str, replacement: str) -> str:
    if source.count(start) != 1 or source.count(end) != 1:
        raise ValueError('Build recipe block context changed')
    a, b = source.index(start), source.index(end)
    if a >= b:
        raise ValueError('Build recipe block order changed')
    return source[:a] + replacement + '\n\n' + source[b:]


def php_recipe(original: str, manifest: dict) -> str:
    profile = manifest['toolchain']
    source = prepare.prepare_dockerfile(original, candidate=True, jobs=profile['jobs'])
    source = prepare.replace_exact(source, 'FROM playground-php-wasm:base', 'FROM native-libraries')
    source = replace_block(source, 'RUN PHP_REF="${PHP_REF:-php-$PHP_VERSION}"',
                           '# Work around memory leak due to PHP using Emscripten',
                           'RUN python3 /builder/source-build.py extract-php\n'
                           'COPY ./compile/ppphp-prepare.py /root/ppphp-prepare.py\n'
                           'RUN python3 /root/ppphp-prepare.py --patch-fibers /root/php-src/Zend/zend_fibers.c')
    source = replace_block(source, '# Bring in the libraries', '# Patch OPcache config.m4',
                           '# Native dependencies were built and verified in native-libraries.')
    source = prepare.replace_exact(source, 'RUN apt install -y bison;', '# Host tools are pinned in the toolchain image.')
    source = prepare.replace_exact(source, '\t\tcp -r /libs/libzip/1.9.2/* /root/lib; \\\n', '')
    source = prepare.replace_exact(source, '\t\tcp -r /libs/libopenssl/* /root/lib; \\\n', '')
    source = replace_block(source, '# Download and prepare imagick extension',
                           '# Disable phar.php generation', '# Imagick is absent from the retained extension profile.')
    # Guest dynamic loading is denied. Export only the explicit integration ABI,
    # never derive build inputs from unrelated prebuilt side-module binaries.
    source = replace_block(source, 'COPY ./${EMSCRIPTEN_ENVIRONMENT}-builds/', '# Build the final .wasm file',
                           'RUN python3 /builder/source-build.py exports')
    source = prepare.replace_exact(source, '$(cat /root/.emcc-php-wasm-sources)',
                           '$(cat /root/.emcc-php-wasm-sources) $(python3 /builder/source-build.py link-arguments)')
    # Keep the historical PHP-only compiler wrapper, never apply it to native libraries.
    setup = '''RUN cp /emsdk/upstream/emscripten/emcc /emsdk/upstream/emscripten/emcc2 && \\
    cp /emsdk/upstream/emscripten/emcc.py /emsdk/upstream/emscripten/emcc2.py && \\
    cp /root/emcc-for-php-wasm.sh /emsdk/upstream/emscripten/emcc && \\
    chmod +x /emsdk/upstream/emscripten/emcc
'''
    source = prepare.replace_exact(source, 'FROM native-libraries\n', 'FROM native-libraries\n' + setup)
    if any(fragment in source for fragment in ['/root/builds', '/libs/lib', 'git clone', 'apt install', 'dist/root/lib']):
        raise ValueError('Candidate recipe still consumes unverified inputs')
    return source + '\nRUN python3 /builder/source-build.py receipt\n'


def tools_recipe(manifest: dict) -> str:
    profile = manifest['toolchain']
    snapshot = profile['ubuntuSnapshot']
    return f'''FROM {profile['image']}
SHELL ["/bin/bash", "-eo", "pipefail", "-c"]
ENV DEBIAN_FRONTEND=noninteractive TZ=UTC LC_ALL=C SOURCE_DATE_EPOCH={profile['sourceDateEpoch']} PYTHONDONTWRITEBYTECODE=1
RUN test "$(find /etc/apt/sources.list.d -type f | wc -l)" = 1 && \\
    sed -i -E 's|https?://(archive\\.|security\\.)?ubuntu.com/ubuntu/?|https://snapshot.ubuntu.com/ubuntu/{snapshot}/|g' /etc/apt/sources.list.d/ubuntu.sources && \\
    test "$(grep -c '^URIs: https://snapshot.ubuntu.com/ubuntu/{snapshot}/$' /etc/apt/sources.list.d/ubuntu.sources)" = 2 && \\
    apt-get update && apt-get install --no-install-recommends -y {' '.join(profile['hostPackages'])}
RUN emcc --version | head -1 | grep -F ' {profile['emscripten']} '
WORKDIR /root
RUN ln -s /emsdk /root/emsdk
'''


def prepare_context(store: Path, destination: Path, manifest: dict) -> None:
    destination.mkdir()
    inputs = destination / 'inputs'
    inputs.mkdir()
    acquisitions = {}
    for name, source in manifest['sources'].items():
        suffix = '.tar.xz' if source['url'].endswith('.xz') else '.tar.gz'
        path = store / (name + suffix)
        acquisitions[name] = native.verify_source(path, source)
        shutil.copyfile(path, inputs / path.name)
    builder = destination / 'builder'
    builder.mkdir()
    for name in ['native.py', 'native-recipes.py', 'source-build.py', 'native-inputs.json', 'prepare.py']:
        shutil.copyfile(HERE / name, builder / name)
    # Select source-bearing integration directories. No upstream dist, extension
    # binaries, bundled archives or generic directory merge enters the context.
    selected = ('php/', 'base-image/', 'php-wasm-memory-storage/', 'php-wasm-dns-polyfill/',
                'php-post-message-to-js/', 'opcache/')
    with tarfile.open(store / 'wordpress-playground.tar.gz') as archive:
        prefix = f'wordpress-playground-{prepare.UPSTREAM}/packages/php-wasm/compile/'
        for member in archive.getmembers():
            if not member.name.startswith(prefix):
                continue
            name = member.name[len(prefix):]
            if not name.startswith(selected) or member.isdir():
                continue
            if not member.isfile() or '/dist/' in name or name.endswith(('.a', '.wasm', '.so')):
                raise ValueError('Unexpected non-source integration input')
            target = destination / 'compile' / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(archive.extractfile(member).read())
            target.chmod(0o755 if member.mode & 0o111 else 0o644)
    shutil.copyfile(HERE / 'prepare.py', destination / 'compile/ppphp-prepare.py')
    bridge = destination / 'compile/php/phpwasm-emscripten-library.js'
    bridge.write_text(prepare.patch_bridge_errno(bridge.read_text()))
    original = (destination / 'compile/php/Dockerfile').read_text()
    (destination / 'Toolchain.Dockerfile').write_text(tools_recipe(manifest))
    layers = '''ARG TOOLCHAIN_IMAGE
FROM ${TOOLCHAIN_IMAGE} AS native-libraries
SHELL ["/bin/bash", "-eo", "pipefail", "-c"]
COPY builder /builder
COPY inputs /inputs
COPY compile/base-image/replace.sh compile/base-image/replace-across-lines.sh compile/base-image/emcc-for-php-wasm.sh /root/
RUN chmod +x /root/replace.sh /root/replace-across-lines.sh
'''
    for name, source in manifest['sources'].items():
        if 'libraries' in source:
            layers += f'RUN python3 /builder/native-recipes.py build {name}\n'
    layers += 'RUN python3 /builder/native-recipes.py assemble\n'
    (destination / 'Native.Dockerfile').write_text(layers)
    candidate = php_recipe(original, manifest)
    candidate = prepare.replace_exact(candidate, 'FROM native-libraries\n', 'ARG NATIVE_IMAGE\nFROM ${NATIVE_IMAGE}\n')
    (destination / 'Candidate.Dockerfile').write_text(candidate)
    (destination / 'acquisitions.json').write_bytes(native.canonical(acquisitions))


def build(store: Path, output: Path, manifest: dict, clean: bool) -> None:
    output = native.require_temporary(output)
    if output.exists():
        raise ValueError('Candidate output must be a new directory')
    # Check the actual backend and resources before preparing a large context.
    subprocess.run(['docker', 'info', '--format', '{{.OSType}} {{.Architecture}} {{.MemTotal}}'], check=True, timeout=20)
    if shutil.disk_usage(output.parent).free < 8 * 1024 ** 3:
        raise ValueError('Source build needs at least 8 GiB free; do not prune unrelated caches')
    output.mkdir()
    context = output / 'context'
    prepare_context(store, context, manifest)
    profile = manifest['toolchain']
    identity = native.digest(HERE / 'native-inputs.json')[:16]
    tools_tag = 'ppphp-native-tools:' + identity
    native_tag = 'ppphp-native-libraries:' + identity
    image_tag = 'ppphp-native-candidate:' + identity
    def run(*args: str, timeout: int = 10800) -> None:
        subprocess.run(args, check=True, timeout=timeout)
    started = time.monotonic()
    run('docker', 'build', '--platform', profile['platform'], '--progress=plain',
        '-f', str(context / 'Toolchain.Dockerfile'), '-t', tools_tag, str(context), timeout=1200)
    tools_image = subprocess.check_output(['docker', 'image', 'inspect', '--format', '{{.Id}}', tools_tag], text=True).strip()
    flags = ['PHP_VERSION=' + prepare.PHP_VERSION, 'PHP_REF=php-' + prepare.PHP_VERSION,
             'WITH_JSPI=no', 'WITH_FILEINFO=yes', 'WITH_LIBXML=yes', 'WITH_SOAP=yes',
             'WITH_LIBZIP=yes', 'WITH_EXIF=yes', 'WITH_GD=yes', 'WITH_MBSTRING=yes', 'WITH_MBREGEX=yes',
             'WITH_CLI_SAPI=yes', 'WITH_OPENSSL=yes', 'WITH_NODEFS=no', 'WITH_CURL=yes',
             'WITH_SQLITE=yes', 'WITH_SOURCEMAPS=no', 'WITH_DEBUG=no', 'WITH_ICONV=yes',
             'WITH_MYSQL=no', 'WITH_WS_NETWORKING_PROXY=yes', 'WITH_IMAGICK=no',
             'EMSCRIPTEN_ENVIRONMENT=web', 'WITH_OPCACHE=yes', 'STACK_SIZE=1MB',
             'OUTPUT_DIR_ON_HOST=/source-build', 'DEBUG_DWARF_COMPILATION_DIR=/source-build']
    native_command = ['docker', 'build', '--platform', profile['platform'], '--network=none', '--progress=plain',
                      '--build-arg', 'TOOLCHAIN_IMAGE=' + tools_image, '-f', str(context / 'Native.Dockerfile'), '-t', native_tag]
    if clean:
        native_command.append('--no-cache')
    native_command.append(str(context))
    command = native_command
    try:
        run(*native_command, timeout=5400)
        native_image = subprocess.check_output(['docker', 'image', 'inspect', '--format', '{{.Id}}', native_tag], text=True).strip()
        checkpoint = output / 'native-checkpoint'
        checkpoint.mkdir()
        container = subprocess.check_output(['docker', 'create', '--network=none', native_image], text=True).strip()
        try:
            run('docker', 'cp', f'{container}:/opt/native', str(checkpoint / 'prefixes'), timeout=180)
            run('docker', 'cp', f'{container}:/receipts', str(checkpoint / 'receipts'), timeout=60)
        finally:
            run('docker', 'rm', container, timeout=20)
        command = ['docker', 'build', '--platform', profile['platform'], '--network=none', '--progress=plain',
                   '--build-arg', 'NATIVE_IMAGE=' + native_image, '-f', str(context / 'Candidate.Dockerfile'), '-t', image_tag]
        if clean:
            command.append('--no-cache')
        for flag in flags:
            command += ['--build-arg', flag]
        command.append(str(context))
        run(*command)
    finally:
        (output / 'execution.json').write_bytes(native.canonical({'elapsedSeconds': round(time.monotonic() - started, 3),
            'toolchainImage': tools_image, 'cleanCompiledLayers': clean, 'nativeCommand': native_command, 'command': command,
            'diskFreeBytesAfter': shutil.disk_usage(output).free, 'productionReady': False}))
    artifact = output / 'candidate'
    artifact.mkdir()
    container = subprocess.check_output(['docker', 'create', '--network=none', image_tag], text=True).strip()
    try:
        for source, target in [('/root/output', 'asyncify'), ('/receipts', 'native'),
                               ('/root/php-src/Zend/zend_fibers.c', 'zend_fibers.c'),
                               ('/root/.emcc-php-asyncify-flags', 'asyncify-flags.txt'),
                               ('/root/.emcc-php-wasm-flags', 'link-flags.txt')]:
            run('docker', 'cp', f'{container}:{source}', str(artifact / target), timeout=120)
    finally:
        run('docker', 'rm', container, timeout=20)
    (artifact / 'build-arguments.txt').write_text('\n'.join(flags) + '\n')
    (artifact / 'artifacts.json').write_bytes(native.canonical({'profile': 'candidate', 'productionReady': False,
                                                              'files': native.inventory(artifact)}))
    print(json.dumps({'candidate': str(artifact), 'elapsedSeconds': round(time.monotonic() - started, 3)}))


def inside(command: str, manifest: dict) -> None:
    if command == 'extract-php':
        native.extract_source(Path('/inputs/php-src.tar.gz'), Path('/root/php-src'),
                              manifest['sources']['php-src'], manifest['toolchain']['sourceDateEpoch'])
    elif command == 'exports':
        js = sorted(set(Path('/root/.JS_ABI_EXPORTS').read_text().split()))
        wasm = sorted(set(Path('/root/.WASM_ABI_EXPORTS').read_text().split()))
        Path('/root/.exported-functions').write_text(json.dumps(js) + '\n')
        Path('/root/.wasm-exports').write_text(''.join('-Wl,--export=' + name + '\n' for name in wasm))
    elif command == 'link-arguments':
        libraries = json.loads(Path('/receipts/link-libraries.json').read_text())
        for path in libraries:
            native.verify_wasm_archive(Path(path))
        print('-Wl,--start-group ' + ' '.join(libraries) + ' -Wl,--end-group')
    else:
        libraries = json.loads(Path('/receipts/link-libraries.json').read_text())
        # This is build provenance, not a compatibility or production approval.
        receipt = {'schemaVersion': 1, 'profile': manifest['profile'], 'productionReady': False,
                   'manifest': manifest, 'recipes': native.inventory(HERE),
                   'nativeReceipts': native.inventory(Path('/receipts')),
                   'finalLinkLibraries': [{'path': path, 'sha256': native.digest(Path(path))} for path in libraries],
                   'phpArchive': {'sha256': native.digest(Path('/root/lib/libphp.a'))},
                   'phpConfiguration': native.digest(Path('/root/php-src/main/php_config.h')),
                   'fiberSource': native.digest(Path('/root/php-src/Zend/zend_fibers.c')),
                   'runtimeFiles': native.inventory(Path('/root/output')),
                   'toolVersions': {tool: subprocess.check_output(argv, text=True, stderr=subprocess.STDOUT, timeout=20).strip()
                                    for tool, argv in {'emcc': ['emcc', '--version'], 'cmake': ['cmake', '--version'],
                                                       'packages': ['dpkg-query', '-W', '-f=${Package}=${Version}\n']}.items()}}
        Path('/receipts/runtime-build.json').write_bytes(native.canonical(receipt))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['prepare', 'build', 'extract-php', 'exports', 'link-arguments', 'receipt'])
    parser.add_argument('--store', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--clean', action='store_true')
    args = parser.parse_args()
    manifest = native.load_manifest()
    if args.command in ('prepare', 'build'):
        if not args.store or not args.output:
            parser.error('--store and --output are required')
        if args.command == 'prepare':
            prepare_context(args.store, native.require_temporary(args.output), manifest)
        else:
            build(args.store, args.output, manifest, args.clean)
    else:
        inside(args.command, manifest)


if __name__ == '__main__':
    main()

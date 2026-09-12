#!/usr/bin/env python3
"""Target-only recipes. Run inside the pinned image with networking disabled."""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import time
from pathlib import Path

import native
from prepare import replace_exact

MANIFEST = native.load_manifest()
PROFILE = MANIFEST['toolchain']
JOBS = str(PROFILE['jobs'])
PREFIX_ROOT = Path('/opt/native')


def verify_headers(prefix: Path, source: dict) -> None:
    for name in source['headers']:
        path = prefix / name
        if not path.is_file() or not path.resolve().is_relative_to(prefix.resolve()) or path.stat().st_size == 0:
            raise ValueError(f'Missing or unsafe installed native header: {path}')


def patch_gif_decoder(content: str) -> str:
    # PHP upstream fcd691b377d02285740744bee17c0f298be227d5,
    # adapted only to the external GD release's whitespace (CVE-2026-9672).
    content = replace_exact(content, 'sd->table[0][i] = sd->table[1][0] = 0;',
                            'sd->table[0][i] = sd->table[1][i] = 0;')
    content = replace_exact(content, 'LZW_STATIC_DATA sd;', 'LZW_STATIC_DATA sd = {0};')
    return replace_exact(content, '\t\t\tif(count != 0) {\n\t\t\t\treturn -2;\n\t\t\t}\n\t\t}\n\n\t\tincode = code;',
                          '\t\t\tif(count != 0) {\n\t\t\t\treturn -2;\n\t\t\t}\n\t\t\treturn -2;\n\t\t}\n\n\t\tincode = code;')


def build(name: str) -> None:
    source = MANIFEST['sources'][name]
    prefix = PREFIX_ROOT / name
    if prefix.exists():
        raise ValueError('Refuse an existing native output prefix')
    suffix = '.tar.xz' if source['url'].endswith('.xz') else '.tar.gz'
    archive = Path('/inputs') / (name + suffix)
    root = Path('/src') / name
    root.parent.mkdir(exist_ok=True)
    native.extract_source(archive, root, source, PROFILE['sourceDateEpoch'])
    dependencies = []
    def visit(dependency: str) -> None:
        for parent in MANIFEST['sources'][dependency]['dependencies']:
            visit(parent)
        if dependency not in dependencies:
            verify(dependency)
            dependencies.append(dependency)
    for dependency in source['dependencies']:
        visit(dependency)
    view = Path('/opt/views') / name
    view.parent.mkdir(exist_ok=True)
    native.assemble([PREFIX_ROOT / dep for dep in dependencies], view)
    env = {**os.environ, 'CC': 'emcc', 'CXX': 'em++', 'AR': 'emar', 'RANLIB': 'emranlib',
           'CFLAGS': PROFILE['cflags'], 'CXXFLAGS': PROFILE['cflags'],
           'CPPFLAGS': f'-I{view}/include', 'LDFLAGS': f'-L{view}/lib',
           'PKG_CONFIG_PATH': '', 'PKG_CONFIG_LIBDIR': f'{view}/lib/pkgconfig',
           'SOURCE_DATE_EPOCH': str(PROFILE['sourceDateEpoch']), 'ZERO_AR_DATE': '1'}
    commands, patches = [], []
    started = time.monotonic()
    def run(*args: str, cwd: Path = root) -> None:
        commands.append({'argv': list(args), 'cwd': str(cwd)})
        subprocess.run(args, cwd=cwd, env=env, check=True, timeout=1800)
    def patch(file: str, old: str, new: str, count: int = 1) -> None:
        path = root / file
        before = native.digest(path)
        path.write_text(replace_exact(path.read_text(), old, new, count))
        patches.append({'path': file, 'beforeSha256': before, 'afterSha256': native.digest(path)})
    def cmake(*options: str, target: str | None = None, install: bool = True) -> None:
        run('emcmake', 'cmake', '-S', '.', '-B', '_build', '-G', 'Unix Makefiles',
            '-DCMAKE_BUILD_TYPE=Release', f'-DCMAKE_INSTALL_PREFIX={prefix}',
            '-DCMAKE_INSTALL_LIBDIR=lib', f'-DCMAKE_PREFIX_PATH={view}',
            f'-DCMAKE_FIND_ROOT_PATH={view}', '-DCMAKE_FIND_ROOT_PATH_MODE_LIBRARY=ONLY',
            '-DCMAKE_FIND_ROOT_PATH_MODE_INCLUDE=ONLY', '-DCMAKE_FIND_ROOT_PATH_MODE_PACKAGE=ONLY',
            '-DCMAKE_FIND_USE_PACKAGE_REGISTRY=OFF', '-DCMAKE_FIND_USE_SYSTEM_PACKAGE_REGISTRY=OFF',
            '-DCMAKE_POSITION_INDEPENDENT_CODE=ON', '-DBUILD_SHARED_LIBS=OFF',
            '-DFETCHCONTENT_FULLY_DISCONNECTED=ON', '-DFETCHCONTENT_UPDATES_DISCONNECTED=ON', *options)
        run('cmake', '--build', '_build', '--parallel', JOBS, *(('--target', target) if target else ()))
        if install:
            run('cmake', '--install', '_build')
    def copy(file: str, destination: str) -> None:
        target = prefix / destination
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(root / file, target)
        commands.append({'copy': file, 'destination': str(target)})
    if name == 'zlib':
        run('emconfigure', './configure', '--static', f'--prefix={prefix}')
        run('emmake', 'make', '-j' + JOBS, 'libz.a')
        run('emmake', 'make', 'install')
    elif name == 'openssl':
        # generic32 describes pointers/size_t, not PHP's zend_long. The SDK supplies getentropy.
        run('perl', './Configure', 'linux-generic32', 'no-shared', 'no-module', 'no-dso',
            'no-asm', 'no-threads', 'no-tests', 'no-legacy', 'no-afalgeng', 'no-ui-console',
            '--with-rand-seed=getrandom', f'--prefix={prefix}', '--libdir=lib',
            f'--openssldir={prefix}/ssl', '-DHAVE_FORK=0', '-DOPENSSL_NO_SECURE_MEMORY', '-fPIC')
        run('emmake', 'make', '-j' + JOBS, 'build_libs')
        run('emmake', 'make', 'install_dev')
    elif name in ('libiconv', 'oniguruma'):
        run('emconfigure', './configure', '--host=wasm32-unknown-emscripten',
            f'--prefix={prefix}', '--disable-shared', '--enable-static')
        if name == 'libiconv':
            run('emmake', 'make', 'lib/localcharset.h')
            run('emmake', 'make', '-C', 'lib', '-j' + JOBS)
            run('emmake', 'make', '-C', 'libcharset', '-j' + JOBS)
            run('emmake', 'make', '-C', 'lib', 'install')
            # The top-level install rule copies this generated public header;
            # include/ has no install target. Do not build the unused iconv CLI.
            copy('include/iconv.h.inst', 'include/iconv.h')
            run('emmake', 'make', '-C', 'libcharset', 'install')
        else:
            run('emmake', 'make', '-j' + JOBS)
            run('emmake', 'make', 'install')
    elif name == 'sqlite':
        run('emcc', *PROFILE['cflags'].split(), '-DSQLITE_THREADSAFE=0',
            '-DSQLITE_ENABLE_COLUMN_METADATA=1', '-DSQLITE_ENABLE_FTS5=1', '-DSQLITE_USE_URI=1',
            '-c', 'sqlite3.c', '-o', 'sqlite3.o')
        run('emar', 'rcsD', 'libsqlite3.a', 'sqlite3.o')
        copy('libsqlite3.a', 'lib/libsqlite3.a')
        copy('sqlite3.h', 'include/sqlite3.h')
        copy('sqlite3ext.h', 'include/sqlite3ext.h')
        pc = prefix / 'lib/pkgconfig/sqlite3.pc'
        pc.parent.mkdir(parents=True)
        pc.write_text(f'prefix={prefix}\nlibdir=${{prefix}}/lib\nincludedir=${{prefix}}/include\n'
                      f'Name: SQLite\nDescription: SQLite library\nVersion: {source["version"]}\n'
                      'Libs: -L${libdir} -lsqlite3\nCflags: -I${includedir}\n')
    elif name == 'libxml2':
        cmake('-DLIBXML2_WITH_PROGRAMS=OFF', '-DLIBXML2_WITH_PYTHON=OFF',
              '-DLIBXML2_WITH_TESTS=OFF', '-DLIBXML2_WITH_THREADS=OFF', '-DLIBXML2_WITH_ICONV=ON',
              f'-DIconv_INCLUDE_DIR={view}/include', f'-DIconv_LIBRARY={view}/lib/libiconv.a')
    elif name == 'curl':
        cmake('-DBUILD_CURL_EXE=OFF', '-DBUILD_TESTING=OFF', '-DBUILD_EXAMPLES=OFF',
              '-DCURL_USE_OPENSSL=ON', '-DOPENSSL_USE_STATIC_LIBS=TRUE', f'-DOPENSSL_ROOT_DIR={view}',
              '-DCURL_ZLIB=ON', f'-DZLIB_LIBRARY={view}/lib/libz.a', f'-DZLIB_INCLUDE_DIR={view}/include',
              '-DCURL_USE_LIBPSL=OFF', '-DCURL_BROTLI=OFF', '-DCURL_ZSTD=OFF',
              '-DUSE_LIBIDN2=OFF', '-DCURL_USE_LIBSSH2=OFF', '-DENABLE_THREADED_RESOLVER=OFF',
              '-DENABLE_ARES=OFF', '-DCURL_DISABLE_LDAP=ON',
              '-DCURL_DISABLE_LDAPS=ON', '-DCURL_DISABLE_TELNET=ON')
    elif name == 'libzip':
        cmake('-DBUILD_TOOLS=OFF', '-DBUILD_REGRESS=OFF', '-DBUILD_EXAMPLES=OFF', '-DBUILD_DOC=OFF',
              '-DENABLE_OPENSSL=ON', '-DOPENSSL_USE_STATIC_LIBS=TRUE', f'-DOPENSSL_ROOT_DIR={view}',
              f'-DZLIB_LIBRARY={view}/lib/libz.a', f'-DZLIB_INCLUDE_DIR={view}/include',
              '-DENABLE_BZIP2=OFF', '-DENABLE_LZMA=OFF', '-DENABLE_ZSTD=OFF',
              '-DENABLE_GNUTLS=OFF', '-DENABLE_MBEDTLS=OFF', '-DENABLE_COMMONCRYPTO=OFF')
    elif name == 'libjpeg-turbo':
        cmake('-DENABLE_SHARED=OFF', '-DENABLE_STATIC=ON', '-DWITH_SIMD=OFF',
              '-DWITH_TURBOJPEG=OFF', target='jpeg-static', install=False)
        copy('_build/libjpeg.a', 'lib/libjpeg.a')
        for file in ['jpeglib.h', 'jmorecfg.h', 'jerror.h']:
            copy('src/' + file, 'include/' + file)
        copy('_build/jconfig.h', 'include/jconfig.h')
        copy('_build/pkgscripts/libjpeg.pc', 'lib/pkgconfig/libjpeg.pc')
    elif name == 'libpng':
        cmake('-DPNG_SHARED=OFF', '-DPNG_STATIC=ON', '-DPNG_TESTS=OFF', '-DPNG_TOOLS=OFF',
              f'-DZLIB_LIBRARY={view}/lib/libz.a', f'-DZLIB_INCLUDE_DIR={view}/include')
    elif name == 'libwebp':
        cmake('-DWEBP_BUILD_ANIM_UTILS=OFF', '-DWEBP_BUILD_CWEBP=OFF', '-DWEBP_BUILD_DWEBP=OFF',
              '-DWEBP_BUILD_GIF2WEBP=OFF', '-DWEBP_BUILD_IMG2WEBP=OFF', '-DWEBP_BUILD_VWEBP=OFF',
              '-DWEBP_BUILD_WEBPINFO=OFF', '-DWEBP_BUILD_WEBPMUX=OFF', '-DWEBP_BUILD_EXTRAS=OFF',
              '-DWEBP_USE_THREAD=OFF', '-DWEBP_ENABLE_SIMD=OFF')
    elif name == 'libaom':
        cmake('-DAOM_TARGET_CPU=generic', '-DENABLE_DOCS=OFF', '-DENABLE_EXAMPLES=OFF', '-DENABLE_APPS=OFF',
              '-DENABLE_TESTS=OFF', '-DENABLE_TESTDATA=OFF', '-DENABLE_TOOLS=OFF',
              '-DCONFIG_MULTITHREAD=0', '-DCONFIG_RUNTIME_CPU_DETECT=0', '-DCONFIG_WEBM_IO=0',
              '-DCONFIG_ACCOUNTING=1', '-DCONFIG_INSPECTION=0')
    elif name == 'libavif':
        cmake('-DAVIF_CODEC_AOM=SYSTEM', '-DAVIF_CODEC_DAV1D=OFF', '-DAVIF_CODEC_LIBGAV1=OFF',
              '-DAVIF_CODEC_RAV1E=OFF', '-DAVIF_CODEC_SVT=OFF', '-DAVIF_LIBYUV=OFF',
              '-DAVIF_BUILD_APPS=OFF', '-DAVIF_BUILD_TESTS=OFF', '-DAVIF_ENABLE_GTEST=OFF')
    elif name == 'libgd':
        original = (root / 'src/gd_gif_in.c').read_text()
        patch('src/gd_gif_in.c', original, patch_gif_decoder(original))
        # Rename only GD's private helper; keep each implementation's overflow checks intact.
        for file in root.joinpath('src').glob('*'):
            if file.suffix in ('.c', '.h') and 'overflow2' in file.read_text():
                content = file.read_text()
                patch('src/' + file.name, 'overflow2', 'gd_checked_multiply_overflow', content.count('overflow2'))
        patch('src/gdft.c', '#ifndef HAVE_LIBFREETYPE\nBGD_DECLARE(char *)',
              '#ifndef HAVE_LIBFREETYPE\n/* No font cache exists in this configuration. */\n'
              'BGD_DECLARE(void) gdFontCacheShutdown(void) {}\n'
              'BGD_DECLARE(void) gdFreeFontCache(void) {}\nBGD_DECLARE(char *)')
        cmake('-DBUILD_STATIC_LIBS=ON', '-DBUILD_TEST=OFF', '-DENABLE_CPP=OFF',
              '-DENABLE_GD_FORMATS=ON', '-DENABLE_PNG=ON', '-DENABLE_JPEG=ON', '-DENABLE_WEBP=ON',
              '-DENABLE_AVIF=ON', '-DENABLE_ICONV=ON', '-DENABLE_LIQ=OFF', '-DENABLE_TIFF=OFF',
              '-DENABLE_XPM=OFF', '-DENABLE_FREETYPE=OFF', '-DENABLE_FONTCONFIG=OFF',
              '-DENABLE_HEIF=OFF', '-DENABLE_RAQM=OFF', f'-DICONV_INCLUDE_DIR={view}/include',
              f'-DICONV_LIBRARY={view}/lib/libiconv.a', f'-DZLIB_LIBRARY={view}/lib/libz.a',
              f'-DZLIB_INCLUDE_DIR={view}/include', f'-DPNG_LIBRARY={view}/lib/libpng16.a',
              f'-DPNG_PNG_INCLUDE_DIR={view}/include', f'-DJPEG_LIBRARY={view}/lib/libjpeg.a',
              f'-DJPEG_INCLUDE_DIR={view}/include', f'-DWEBP_LIBRARY={view}/lib/libwebp.a',
              f'-DWEBP_INCLUDE_DIR={view}/include', target='gd_static', install=False)
        copy('_build/Bin/libgd.a', 'lib/libgd.a')
        for file in ['gd.h', 'gd_color_map.h', 'gd_errors.h', 'gd_io.h', 'gdcache.h',
                     'gdfontg.h', 'gdfontl.h', 'gdfontmb.h', 'gdfonts.h', 'gdfontt.h', 'gdfx.h']:
            copy('src/' + file, 'include/' + file)
        copy('_build/src/gdlib.pc', 'lib/pkgconfig/gdlib.pc')
    else:
        raise ValueError(f'No reviewed recipe for {name}')
    verify_headers(prefix, source)
    objects = {file: native.verify_wasm_archive(prefix / file) for file in source['libraries']}
    configs = []
    for file in sorted(root.rglob('*')):
        if file.is_file() and file.name in ('CMakeCache.txt', 'config.h', 'configdata.pm', 'Makefile'):
            configs.append({'path': file.relative_to(root).as_posix(), 'sha256': native.digest(file)})
    receipt = {'schemaVersion': 1, 'name': name, 'input': source, 'archive': native.verify_source(archive, source),
               'toolchain': PROFILE, 'recipeSha256': native.digest(Path(__file__)),
               'orchestratorSha256': native.digest(native.HERE / 'native.py'),
               'dependencies': {dep: native.digest(Path('/receipts') / (dep + '.json')) for dep in dependencies},
               'commands': commands, 'environment': {key: env[key] for key in
                   ['CC', 'CXX', 'AR', 'RANLIB', 'CFLAGS', 'CXXFLAGS', 'CPPFLAGS', 'LDFLAGS',
                    'PKG_CONFIG_PATH', 'PKG_CONFIG_LIBDIR', 'SOURCE_DATE_EPOCH', 'ZERO_AR_DATE']},
               'patches': patches, 'configurations': configs, 'targetObjects': objects,
               'outputs': native.inventory(prefix)}
    Path('/receipts').mkdir(exist_ok=True)
    (Path('/receipts') / (name + '.json')).write_bytes(native.canonical(receipt))
    # Timing is evidence, deliberately separate from the reproducible build identity.
    print(json.dumps({'library': name, 'elapsedSeconds': round(time.monotonic() - started, 3),
                      'targetObjects': sum(objects.values())}), flush=True)


def verify(name: str) -> None:
    receipt = json.loads((Path('/receipts') / (name + '.json')).read_text())
    source = MANIFEST['sources'][name]
    verify_headers(PREFIX_ROOT / name, source)
    if (receipt['input'] != source or receipt['toolchain'] != PROFILE
            or receipt['recipeSha256'] != native.digest(Path(__file__))
            or receipt['orchestratorSha256'] != native.digest(native.HERE / 'native.py')
            or receipt['outputs'] != native.inventory(PREFIX_ROOT / name)):
        raise ValueError(f'Unverified native checkpoint: {name}')
    for dep, identity in receipt['dependencies'].items():
        if native.digest(Path('/receipts') / (dep + '.json')) != identity:
            raise ValueError('Dependency receipt identity mismatch')
    for file in source['libraries']:
        native.verify_wasm_archive(PREFIX_ROOT / name / file)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['build', 'assemble'])
    parser.add_argument('name', nargs='?')
    args = parser.parse_args()
    if args.command == 'build':
        build(args.name)
    else:
        names = [name for name, source in MANIFEST['sources'].items() if 'libraries' in source]
        for name in names:
            verify(name)
        native.assemble([PREFIX_ROOT / name for name in names], Path('/root/lib'))
        # All archives, including transitive AOM and charset, are explicit final inputs.
        libraries = ['/root/lib/' + file for name in names for file in MANIFEST['sources'][name]['libraries']]
        Path('/receipts/link-libraries.json').write_bytes(native.canonical(libraries))


if __name__ == '__main__':
    main()

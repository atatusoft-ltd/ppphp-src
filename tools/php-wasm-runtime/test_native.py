import ast
import hashlib
import importlib.util
import io
import json
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

import native

spec = importlib.util.spec_from_file_location('source_build', native.HERE / 'source-build.py')
source_build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(source_build)
recipe_spec = importlib.util.spec_from_file_location('native_recipes', native.HERE / 'native-recipes.py')
native_recipes = importlib.util.module_from_spec(recipe_spec)
recipe_spec.loader.exec_module(native_recipes)


class NativeTests(unittest.TestCase):
    def archive(self, path, entries):
        with tarfile.open(path, 'w:gz') as archive:
            for name, body, kind in entries:
                member = tarfile.TarInfo(name)
                member.mode = 0o644
                member.type = kind
                if kind in (tarfile.SYMTYPE, tarfile.LNKTYPE):
                    member.linkname = body
                    archive.addfile(member)
                else:
                    member.size = len(body)
                    archive.addfile(member, io.BytesIO(body))
        return {'sha256': native.digest(path), 'bytes': path.stat().st_size, 'stripRoot': True}

    def test_manifest_is_ordered_and_pinned(self):
        manifest = native.load_manifest()
        self.assertEqual(len([s for s in manifest['sources'].values() if 'libraries' in s]), 14)
        self.assertIn('@sha256:', manifest['toolchain']['image'])
        self.assertEqual(manifest['toolchain']['platform'], 'linux/amd64')
        self.assertNotIn('__x86_64__', manifest['toolchain']['cflags'])
        self.assertFalse(manifest['productionReady'])

    def test_every_cmake_recipe_passes_definitions_using_cmake_option_syntax(self):
        tree = ast.parse((native.HERE / 'native-recipes.py').read_text())
        recipes = [node for node in ast.walk(tree) if isinstance(node, ast.Call)
                   and isinstance(node.func, ast.Name) and node.func.id == 'cmake']
        self.assertEqual(len(recipes), 9)
        for recipe in recipes:
            for argument in recipe.args:
                prefix = argument.values[0].value if isinstance(argument, ast.JoinedStr) else argument.value
                self.assertTrue(prefix.startswith('-D') and '=' in prefix, prefix)

    def test_native_checkpoint_requires_installed_public_headers(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / 'prefix'
            (root / 'include').mkdir(parents=True)
            source = {'headers': ['include/zlib.h', 'include/zconf.h']}
            (root / 'include/zlib.h').write_text('public API')
            with self.assertRaisesRegex(ValueError, 'zconf.h'):
                native_recipes.verify_headers(root, source)
            (root / 'include/zconf.h').write_text('target configuration')
            native_recipes.verify_headers(root, source)
            (root / 'include/zconf.h').write_text('')
            with self.assertRaisesRegex(ValueError, 'zconf.h'):
                native_recipes.verify_headers(root, source)
            (root / 'include/zconf.h').unlink()
            (root / 'include/zconf.h').symlink_to('zlib.h')
            native_recipes.verify_headers(root, source)
            (root / 'include/zconf.h').unlink()
            (Path(tmp) / 'outside.h').write_text('outside')
            (root / 'include/zconf.h').symlink_to('../../outside.h')
            with self.assertRaisesRegex(ValueError, 'zconf.h'):
                native_recipes.verify_headers(root, source)

    def test_gif_security_patch_preserves_all_three_upstream_corrections(self):
        source = ('sd->table[0][i] = sd->table[1][0] = 0;\nLZW_STATIC_DATA sd;\n'
                  '\t\t\tif(count != 0) {\n\t\t\t\treturn -2;\n\t\t\t}\n\t\t}\n\n\t\tincode = code;')
        patched = native_recipes.patch_gif_decoder(source)
        self.assertIn('sd->table[1][i] = 0;', patched)
        self.assertIn('LZW_STATIC_DATA sd = {0};', patched)
        self.assertIn('\t\t\t}\n\t\t\treturn -2;\n\t\t}', patched)
        with self.assertRaises(ValueError):
            native_recipes.patch_gif_decoder(patched)
        with self.assertRaises(ValueError):
            native_recipes.patch_gif_decoder(source.replace('LZW_STATIC_DATA sd;', 'changed'))

    def test_native_source_patches_preserve_non_utf8_comments(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'source.c'
            original = b'/* \x82\xa0 */\r\nint overflow2(int a, int b);\r\n'
            path.write_bytes(original)
            receipt = native_recipes.patch_source(path, 'overflow2', 'gd_checked_multiply_overflow')
            self.assertEqual(path.read_bytes(), original.replace(b'overflow2', b'gd_checked_multiply_overflow'))
            self.assertEqual(receipt['beforeSha256'], hashlib.sha256(original).hexdigest())
            with self.assertRaises(ValueError):
                native_recipes.patch_source(path, 'overflow2', 'gd_checked_multiply_overflow')

    def test_gd_uses_the_real_static_avif_package_and_imported_target(self):
        source = ('FIND_PACKAGE(libavif 0.8.2 REQUIRED CONFIG)\n'
                  'SET(HAVE_LIBAVIF 1)\nSET(AVIF_LIBRARIES avif)\nSET(AVIF_FOUND 1)')
        patched = native_recipes.patch_gd_avif_config(source)
        self.assertIn('PKG_CHECK_MODULES(AVIF REQUIRED IMPORTED_TARGET libavif>=0.8.2)', patched)
        self.assertIn('SET(AVIF_LIBRARIES PkgConfig::AVIF)', patched)
        self.assertIn('SET(HAVE_LIBAVIF 1)', patched)
        for invalid in [patched, source + source, source.replace('SET(AVIF_LIBRARIES avif)', '')]:
            with self.assertRaises(ValueError):
                native_recipes.patch_gd_avif_config(invalid)

    def test_bcmath_security_patch_updates_the_copy_endpoint_once(self):
        source = ('\t\t\t\tstr_scale -= fractional_end - fractional_new_end; /* fractional_end >= fractional_new_end */\n'
                  '\t\t\t}')
        patched = source_build.patch_bcmath_bounds(source)
        self.assertIn('\t\t\t\tfractional_end = fractional_new_end;\n\t\t\t}', patched)
        for invalid in [patched, source + source, 'unreviewed source']:
            with self.assertRaises(ValueError):
                source_build.patch_bcmath_bounds(invalid)

    def test_poisoned_or_removed_upstream_dist_does_not_enter_the_source_context(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            prefix = f'wordpress-playground-{source_build.prepare.UPSTREAM}/packages/php-wasm/compile/'
            selected = [(prefix + 'php/source.c', b'integration source', tarfile.REGTYPE)]
            poison = [(prefix + 'libopenssl/asyncify/dist/root/lib/libcrypto.a', b'poison', tarfile.REGTYPE),
                      (prefix + 'oniguruma/asyncify/dist/root/lib/libonig.a', b'poison', tarfile.REGTYPE)]
            for name, entries in [('poisoned', selected + poison), ('removed', selected)]:
                path = root / (name + '.tar.gz')
                self.archive(path, entries)
                with tarfile.open(path) as archive:
                    source_build.copy_integration(archive, root / name)
            self.assertEqual(native.inventory(root / 'poisoned'), native.inventory(root / 'removed'))
            self.assertEqual([row['path'] for row in native.inventory(root / 'poisoned')], ['php/source.c'])

    def test_checksum_rejects_changed_download(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'a.tar.gz'
            source = self.archive(path, [('root/file', b'ok', tarfile.REGTYPE)])
            source['sha256'] = '0' * 64
            with self.assertRaisesRegex(ValueError, 'checksum'):
                native.verify_source(path, source)

    def test_cached_source_rejects_links_even_when_the_target_bytes_match(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = self.archive(root / 'source.tar.gz', [('root/file', b'ok', tarfile.REGTYPE)])
            (root / 'link.tar.gz').symlink_to('source.tar.gz')
            for path in [root / 'link.tar.gz', root, root / 'missing.tar.gz']:
                with self.assertRaisesRegex(ValueError, 'regular file'):
                    native.verify_source(path, source)

    def test_preferred_source_projection_is_pinned_reproducible_and_excludes_prebuilt_inputs(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            original = root / 'original.tar.gz'
            upstream = self.archive(original, [('root/LICENSE', b'notice', tarfile.REGTYPE),
                ('root/src/bridge.c', b'source', tarfile.REGTYPE),
                ('root/old/dist/lib.a', b'prebuilt', tarfile.REGTYPE)])
            source = {'upstreamArchive': upstream, 'sourceProjection': ['LICENSE', 'src'],
                      'stripRoot': True, 'maxBytes': 4096}
            with tarfile.open(original) as archive:
                source['gitTree'] = native.git_tree(archive, native.projection_members(archive, source))
            for name in ['one.tar.gz', 'two.tar.gz']:
                native.project_source(original, root / name, source, 1234567)
                with tarfile.open(root / name) as archive:
                    self.assertEqual(set(native.archive_members(archive, True)), {'LICENSE', 'src/bridge.c'})
            self.assertEqual((root / 'one.tar.gz').read_bytes(), (root / 'two.tar.gz').read_bytes())
            with self.assertRaises(FileExistsError):
                native.project_source(original, root / 'one.tar.gz', source, 1234567)
            self.assertTrue((root / 'one.tar.gz').exists())
            for selection in [['missing'], ['LICENSE', 'old']]:
                with tarfile.open(original) as archive, self.assertRaises(ValueError):
                    native.projection_members(archive, {**source, 'sourceProjection': selection})
            with self.assertRaisesRegex(ValueError, 'tree mismatch'):
                native.project_source(original, root / 'bad.tar.gz', {**source, 'gitTree': '0' * 40}, 1234567)
            self.assertFalse((root / 'bad.tar.gz').exists())

            source['url'] = 'https://example.invalid/source.tar.gz'
            manifest = {'sources': {'source': source}, 'toolchain': {'sourceDateEpoch': 1234567}}
            def download(command, **kwargs):
                self.assertEqual(command[command.index('--max-filesize') + 1], str(upstream['bytes']))
                Path(command[-1]).write_bytes(original.read_bytes())
            with patch.object(native.subprocess, 'run', side_effect=download) as transport:
                native.acquire(root / 'store', manifest)
                native.acquire(root / 'store', manifest)
                self.assertEqual(transport.call_count, 1)
            self.assertEqual(list((root / 'store').iterdir()), [root / 'store/source.tar.gz'])
            self.assertEqual((root / 'store/source.tar.gz').read_bytes(), (root / 'one.tar.gz').read_bytes())

    def test_acquisition_retries_transport_only_and_never_admits_corrupt_source(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            archive = root / 'valid.tar.gz'
            source = {**self.archive(archive, [('root/file', b'ok', tarfile.REGTYPE)]),
                      'url': 'https://example.invalid/source.tar.gz'}
            manifest = {'sources': {'source': source}}
            def download(command, **kwargs):
                self.assertEqual(command[command.index('--retry') + 1], '3')
                self.assertEqual(command[command.index('--retry-max-time') + 1], '600')
                self.assertEqual(kwargs['timeout'], 620)
                Path(command[-1]).write_bytes(archive.read_bytes())
            with patch.object(native.subprocess, 'run', side_effect=download) as transport:
                native.acquire(root / 'store', manifest)
                native.acquire(root / 'store', manifest)
                self.assertEqual(transport.call_count, 1)
                (root / 'store/source.tar.gz').write_bytes(b'corrupt')
                with self.assertRaisesRegex(ValueError, 'checksum'):
                    native.acquire(root / 'store', manifest)
                self.assertEqual(transport.call_count, 1)
            with patch.object(native.subprocess, 'run', side_effect=lambda command, **kw: Path(command[-1]).write_bytes(b'corrupt')) as transport:
                with self.assertRaisesRegex(ValueError, 'checksum'):
                    native.acquire(root / 'bad', manifest)
                self.assertEqual(transport.call_count, 1)
                self.assertEqual(list((root / 'bad').iterdir()), [])

    def test_valid_internal_links_are_preserved(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'a.tar.gz'
            source = self.archive(path, [('root/file', b'ok', tarfile.REGTYPE),
                                         ('root/link', 'file', tarfile.SYMTYPE),
                                         ('root/hard', 'root/file', tarfile.LNKTYPE)])
            destination = Path(tmp) / 'source'
            native.extract_source(path, destination, source, 1234567)
            self.assertTrue((destination / 'link').is_symlink())
            self.assertEqual((destination / 'link').read_bytes(), b'ok')
            self.assertEqual((destination / 'hard').read_bytes(), b'ok')
            self.assertEqual((destination / 'file').stat().st_mtime, 1234567)
            with self.assertRaisesRegex(ValueError, 'existing'):
                native.extract_source(path, destination, source, 1234567)

    def test_unsafe_archives_are_rejected(self):
        cases = [
            [('root/../escape', b'x', tarfile.REGTYPE)],
            [('/root/file', b'x', tarfile.REGTYPE)],
            [('root/link', '../../outside', tarfile.SYMTYPE)],
            [('root/link', '/outside', tarfile.SYMTYPE)],
            [('root/f', b'x', tarfile.REGTYPE), ('root/f', b'y', tarfile.REGTYPE)],
            [('root/dir', 'target', tarfile.SYMTYPE), ('root/dir/file', b'x', tarfile.REGTYPE)],
            [('root/device', b'', tarfile.CHRTYPE)],
            [('root/a', b'x', tarfile.REGTYPE), ('other/b', b'y', tarfile.REGTYPE)],
        ]
        for entries in cases:
            with self.subTest(entries=entries), tempfile.TemporaryDirectory() as tmp:
                path = Path(tmp) / 'a.tar.gz'
                source = self.archive(path, entries)
                with self.assertRaises(ValueError):
                    native.verify_source(path, source)

    def test_git_tree_checks_content_and_modes_not_tar_timestamps(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'a.tar.gz'
            self.archive(path, [('root/file', b'hello\n', tarfile.REGTYPE)])
            blob = hashlib.sha1(b'blob 6\0hello\n').digest()
            body = b'100644 file\0' + blob
            expected = hashlib.sha1(b'tree ' + str(len(body)).encode() + b'\0' + body).hexdigest()
            source = {'gitTree': expected, 'maxBytes': 4096, 'stripRoot': True}
            native.verify_source(path, source)
            source['gitTree'] = '0' * 40
            with self.assertRaisesRegex(ValueError, 'Git tree'):
                native.verify_source(path, source)

    def test_assembly_rejects_even_identical_duplicate_headers(self):
        with tempfile.TemporaryDirectory() as tmp:
            roots = [Path(tmp) / name for name in ['a', 'b']]
            for root in roots:
                (root / 'include').mkdir(parents=True)
                (root / 'include/zlib.h').write_text('identical')
            with self.assertRaisesRegex(ValueError, 'collision'):
                native.assemble(roots, Path(tmp) / 'view')
            self.assertFalse((Path(tmp) / 'view').exists())

    def test_native_object_masquerading_as_archive_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'libfake.a'
            for body, accepted in [(b'\0asm\x01\0\0\0', True), (b'\x7fELF1234', False), (b'BC\xc0\xde1234', False)]:
                header = f'{"test.o/":<16}{0:<12}{0:<6}{0:<6}{100644:<8}{len(body):<10}`\n'.encode()
                path.write_bytes(b'!<arch>\n' + header + body)
                if accepted:
                    self.assertEqual(native.verify_wasm_archive(path), 1)
                else:
                    with self.assertRaisesRegex(ValueError, 'non-WASM'):
                        native.verify_wasm_archive(path)

    def test_unsafe_output_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'temporary'):
            native.require_temporary(native.HERE / 'log-disguised-as-source.json')

    def test_recipe_block_changes_fail_closed(self):
        self.assertEqual(source_build.replace_block('first\nsecond\nlast', 'first', 'last', 'new'), 'new\n\nlast')
        for text in ['first first last', 'last first', 'no anchors']:
            with self.assertRaises(ValueError):
                source_build.replace_block(text, 'first', 'last', 'new')

    def test_final_link_record_preserves_expanded_arguments_and_cannot_be_overwritten(self):
        invoke = '/root/emsdk/upstream/emscripten/emcc2 "${args[@]}" ${EMCC_FLAGS:-}'
        patched = source_build.patch_link_recording(invoke)
        self.assertTrue(patched.endswith(invoke))
        self.assertIn('record-link "${args[@]}" ${EMCC_FLAGS:-}', patched)
        with self.assertRaises(ValueError):
            source_build.patch_link_recording(invoke + '\n' + invoke)
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'command.json'
            arguments = ['input with space.c', '-o', '/build/output/php.js', '-sASYNCIFY=1']
            source_build.record_link(arguments, path)
            self.assertEqual(json.loads(path.read_text())['argv'],
                ['/root/emsdk/upstream/emscripten/emcc2', *arguments])
            with self.assertRaises(FileExistsError):
                source_build.record_link(arguments, path)

    def test_clean_comparison_checks_headers_receipts_and_runtime_not_only_wasm(self):
        with tempfile.TemporaryDirectory() as tmp:
            first, second = Path(tmp) / 'first', Path(tmp) / 'second'
            manifest = {'toolchain': {}, 'sources': {'example': {'libraries': ['lib/example.a']}}}
            for root in (first, second):
                for path in ['candidate/native', 'candidate/asyncify', 'native-checkpoint/prefixes/example/lib',
                             'native-checkpoint/prefixes/example/include', 'native-checkpoint/receipts']:
                    (root / path).mkdir(parents=True)
                (root / 'execution.json').write_text('{"cleanCompiledLayers":true}')
                body = b'\0asm\x01\0\0\0'
                header = f'{"example.o/":<16}{0:<12}{0:<6}{0:<6}{100644:<8}{len(body):<10}`\n'.encode()
                (root / 'native-checkpoint/prefixes/example/lib/example.a').write_bytes(b'!<arch>\n' + header + body)
                (root / 'native-checkpoint/prefixes/example/include/example.h').write_text('header')
                (root / 'native-checkpoint/prefixes/example/include/alias.h').symlink_to('example.h')
                (root / 'native-checkpoint/receipts/example.json').write_bytes(native.canonical({
                    'input': manifest['sources']['example'], 'toolchain': {},
                    'targetObjects': {'lib/example.a': 1},
                    'outputs': native.inventory(root / 'native-checkpoint/prefixes/example')}))
                (root / 'candidate/asyncify/runtime.wasm').write_bytes(body)
                (root / 'candidate/asyncify/runtime.js').write_text('// test loader')
                (root / 'candidate/native/runtime-build.json').write_bytes(native.canonical({
                    'manifest': manifest, 'productionReady': False,
                    'nativeReceipts': native.inventory(root / 'native-checkpoint/receipts'),
                    'runtimeFiles': native.inventory(root / 'candidate/asyncify')}))
            self.assertEqual(source_build.compare_builds(first, second, manifest)['status'], 'PASS')
            for path in ['native-checkpoint/prefixes/example/include/example.h', 'native-checkpoint/receipts/example.json']:
                file = second / path
                original = file.read_bytes()
                file.write_bytes(original + b'changed')
                with self.assertRaisesRegex(ValueError, 'native checkpoint'):
                    source_build.compare_builds(first, second, manifest)
                file.write_bytes(original)
            # Two equally damaged downloads are not reproducible build evidence.
            for root in (first, second):
                alias = root / 'native-checkpoint/prefixes/example/include/alias.h'
                alias.unlink()
                alias.write_text('header')
            with self.assertRaisesRegex(ValueError, 'native checkpoint'):
                source_build.compare_builds(first, second, manifest)
            for root in (first, second):
                alias = root / 'native-checkpoint/prefixes/example/include/alias.h'
                alias.unlink()
                alias.symlink_to('example.h')
            header = second / 'native-checkpoint/prefixes/example/include/example.h'
            producer_path = second / 'native-checkpoint/receipts/example.json'
            receipt_path = second / 'candidate/native/runtime-build.json'
            originals = {path: path.read_bytes() for path in [header, producer_path, receipt_path]}
            header.write_text('different build output')
            producer = json.loads(producer_path.read_text())
            producer['outputs'] = native.inventory(second / 'native-checkpoint/prefixes/example')
            producer_path.write_bytes(native.canonical(producer))
            receipt = json.loads(receipt_path.read_text())
            receipt['nativeReceipts'] = native.inventory(second / 'native-checkpoint/receipts')
            receipt_path.write_bytes(native.canonical(receipt))
            result = source_build.compare_builds(first, second, manifest)
            self.assertEqual(result['status'], 'FAIL')
            self.assertIn('native-checkpoint/prefixes/example/include/example.h', result['differences'])
            for path, original in originals.items():
                path.write_bytes(original)
            (second / 'candidate/asyncify/runtime.js').write_text('changed')
            with self.assertRaisesRegex(ValueError, 'changed runtime'):
                source_build.compare_builds(first, second, manifest)
            with self.assertRaisesRegex(ValueError, 'independent'):
                source_build.compare_builds(first, first, manifest)
            (second / 'execution.json').write_text('{"cleanCompiledLayers":false}')
            with self.assertRaisesRegex(ValueError, 'bypass'):
                source_build.compare_builds(first, second, manifest)

    def test_checkpoint_transport_preserves_internal_links(self):
        workflow = (native.HERE.parent.parent / '.github/workflows/php-wasm-rebuild.yml').read_text()
        command = 'COPYFILE_DISABLE=1 tar -cf "$directory/native-checkpoint.tar" -C "$directory" native-checkpoint'
        self.assertIn(command, workflow)
        self.assertIn('/tmp/ppphp-source-build-*/native-checkpoint.tar', workflow)
        self.assertNotIn('/tmp/ppphp-source-build-*/native-checkpoint/\n', workflow)
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            checkpoint = root / 'native-checkpoint'
            checkpoint.mkdir()
            (checkpoint / 'header.h').write_text('header')
            (checkpoint / 'header.h').chmod(0o755)
            (checkpoint / 'alias.h').symlink_to('header.h')
            subprocess.run(['sh', '-c', 'directory="$1"; ' + command, 'checkpoint', tmp], check=True)
            restored = root / 'restored'
            restored.mkdir()
            with tarfile.open(root / 'native-checkpoint.tar') as archive:
                native.archive_members(archive, strip_root=False)
                archive.extractall(restored, filter='data')
            self.assertEqual(native.inventory(checkpoint), native.inventory(restored / 'native-checkpoint'))
            self.assertEqual((restored / 'native-checkpoint/header.h').stat().st_mode & 0o777, 0o755)

    def test_toolchain_snapshot_does_not_float(self):
        recipe = source_build.tools_recipe(native.load_manifest())
        self.assertIn('snapshot.ubuntu.com/ubuntu/20260910T000000Z/', recipe)
        self.assertIn('sha256:5e56ae2', recipe)
        self.assertNotIn('apt-get upgrade', recipe)

    def test_snapshot_rewrite_executes_and_rewrites_both_verified_package_origins(self):
        recipe = source_build.tools_recipe(native.load_manifest())
        expression = recipe.split("sed -i -E '", 1)[1].split("'", 1)[0]
        result = subprocess.run(['sed', '-E', expression], input=(
            'deb http://archive.ubuntu.com/ubuntu/ jammy main\n'
            'deb http://security.ubuntu.com/ubuntu/ jammy-security main\n'),
            text=True, capture_output=True, check=True)
        self.assertEqual(result.stdout.count('https://snapshot.ubuntu.com/ubuntu/20260910T000000Z/'), 2)
        self.assertNotIn('archive.ubuntu.com', result.stdout)
        self.assertNotIn('security.ubuntu.com', result.stdout)


if __name__ == '__main__':
    unittest.main()

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

    def test_checksum_rejects_changed_download(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'a.tar.gz'
            source = self.archive(path, [('root/file', b'ok', tarfile.REGTYPE)])
            source['sha256'] = '0' * 64
            with self.assertRaisesRegex(ValueError, 'checksum'):
                native.verify_source(path, source)

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

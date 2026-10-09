import unittest
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from unittest.mock import patch
import prepare
import native


class PreparationTests(unittest.TestCase):
    def test_git_blob_identity(self):
        self.assertEqual(prepare.blob_sha(b''), 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391')

    def test_exact_patch_requires_unique_context(self):
        self.assertEqual(prepare.replace_exact('before x after', 'x', 'y'), 'before y after')
        for source in ['absent', 'x x']:
            with self.assertRaises(ValueError):
                prepare.replace_exact(source, 'x', 'y')

    def test_unreviewed_fiber_source_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Unexpected Zend'):
            prepare.patch_fibers('not the pinned PHP source')

    def test_unreviewed_build_recipe_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Unexpected upstream'):
            prepare.prepare_dockerfile('FROM something-else', candidate=False, jobs=2, php_commit=native.load_manifest()['sources']['php-src']['commit'])

    def test_parallelism_is_bounded(self):
        for jobs in [0, -1, 5, 100]:
            with self.assertRaisesRegex(ValueError, 'parallelism'):
                prepare.prepare_dockerfile('', candidate=False, jobs=jobs, php_commit=native.load_manifest()['sources']['php-src']['commit'])

    def fixture(self):
        # Unit fixture for the transformation contract, not a real runtime build.
        return '\n'.join([
            'FROM playground-php-wasm:base',
            "# Work around memory leak due to PHP using Emscripten's incomplete mmap/munmap support",
            'emmake make -j14',
            '-s ASSERTIONS=0 \\',
            '-s ASYNCIFY_IGNORE_INDIRECT=1',
            '$(cat /root/.emcc-php-asyncify-flags) ";',
        ]) + '\n'

    def test_baseline_has_symbols_without_fiber_patch(self):
        source = self.fixture()
        with patch.object(prepare, 'DOCKER_BLOB', prepare.blob_sha(source.encode())):
            result = prepare.prepare_dockerfile(source, candidate=False, jobs=2, php_commit=native.load_manifest()['sources']['php-src']['commit'])
        self.assertIn(native.load_manifest()['sources']['php-src']['commit'], result)
        self.assertIn('-g2 -s ASSERTIONS=1', result)
        self.assertIn('make -j2', result)
        self.assertIn('BINARYEN_CORES=2', result)
        self.assertNotIn('--patch-fibers', result)
        self.assertIn('ASYNCIFY_IGNORE_INDIRECT=1', result)

    def test_dockerfile_uses_the_explicit_source_commit_without_a_duplicate_pin(self):
        source = self.fixture()
        commit = 'a' * 40
        with patch.object(prepare, 'DOCKER_BLOB', prepare.blob_sha(source.encode())):
            result = prepare.prepare_dockerfile(source, candidate=False, jobs=2, php_commit=commit)
        self.assertIn(commit, result)
        self.assertNotIn(native.load_manifest()['sources']['php-src']['commit'], result)

    def test_unsafe_php_commit_is_rejected_before_recipe_transformation(self):
        for value in ['', 'a' * 39, 'a' * 41, '$(id)', 'a' * 40 + '\n', None]:
            with self.assertRaisesRegex(ValueError, 'PHP source commit'):
                prepare.prepare_dockerfile(self.fixture(), candidate=True, jobs=2, php_commit=value)

    def test_copied_fiber_helper_reaches_source_validation_without_repository_imports(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            shutil.copyfile(prepare.__file__, root / 'prepare.py')
            (root / 'fibers.c').write_text('unreviewed')
            result = subprocess.run([sys.executable, str(root / 'prepare.py'), '--patch-fibers', str(root / 'fibers.c')],
                                    text=True, capture_output=True, timeout=10)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Unexpected Zend Fiber source', result.stderr)
            self.assertNotIn('ModuleNotFoundError', result.stderr)

    def test_candidate_enables_patch_and_conservative_instrumentation(self):
        source = self.fixture()
        with patch.object(prepare, 'DOCKER_BLOB', prepare.blob_sha(source.encode())):
            result = prepare.prepare_dockerfile(source, candidate=True, jobs=4, php_commit=native.load_manifest()['sources']['php-src']['commit'])
        self.assertIn('--patch-fibers /root/php-src/Zend/zend_fibers.c', result)
        self.assertIn('ASYNCIFY_IGNORE_INDIRECT=0', result)
        self.assertIn('ASYNCIFY_ONLY=[]', result)
        self.assertIn('make -j4', result)

    def test_changed_anchor_rejected_even_for_otherwise_accepted_recipe(self):
        source = self.fixture().replace('emmake make -j14', 'emmake make -j100')
        with patch.object(prepare, 'DOCKER_BLOB', prepare.blob_sha(source.encode())):
            with self.assertRaisesRegex(ValueError, 'Patch context changed'):
                prepare.prepare_dockerfile(source, candidate=False, jobs=2, php_commit=native.load_manifest()['sources']['php-src']['commit'])

    def test_unreviewed_bridge_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Unexpected PHP-WASM bridge'):
            prepare.patch_bridge_errno('unreviewed')

    def test_bridge_errno_writes_memory_and_uses_numeric_errno(self):
        source = '\n'.join('___errno_location(' + arg + ');' for arg in [
            'ERRNO_CODES.EINVAL', 'ERRNO_CODES.EINVAL', 'ERRNO_CODES.ENOSYS',
            'ERRNO_CODES.EBADF', 'e.code', 'e.errno'])
        with patch.object(prepare, 'BRIDGE_BLOB', prepare.blob_sha(source.encode())):
            result = prepare.patch_bridge_errno(source)
        self.assertEqual(result.count('HEAP32[___errno_location() >> 2] = '), 6)
        self.assertEqual(result.count('= e.errno;'), 2)
        self.assertNotIn('___errno_location(e.', result)
        self.assertNotIn('___errno_location(ERRNO_CODES', result)

    def test_bridge_changed_context_is_rejected(self):
        source = '___errno_location(ERRNO_CODES.EINVAL);'
        with patch.object(prepare, 'BRIDGE_BLOB', prepare.blob_sha(source.encode())):
            with self.assertRaisesRegex(ValueError, 'Patch context changed'):
                prepare.patch_bridge_errno(source)


if __name__ == '__main__':
    unittest.main()

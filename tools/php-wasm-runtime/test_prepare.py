import unittest
from unittest.mock import patch
import prepare


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
            prepare.prepare_dockerfile('FROM something-else', candidate=False, jobs=2)

    def test_parallelism_is_bounded(self):
        for jobs in [0, -1, 5, 100]:
            with self.assertRaisesRegex(ValueError, 'parallelism'):
                prepare.prepare_dockerfile('', candidate=False, jobs=jobs)

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
            result = prepare.prepare_dockerfile(source, candidate=False, jobs=2)
        self.assertIn(prepare.PHP_COMMIT, result)
        self.assertIn('-g2 -s ASSERTIONS=1', result)
        self.assertIn('make -j2', result)
        self.assertIn('BINARYEN_CORES=2', result)
        self.assertNotIn('--patch-fibers', result)
        self.assertIn('ASYNCIFY_IGNORE_INDIRECT=1', result)

    def test_candidate_enables_patch_and_conservative_instrumentation(self):
        source = self.fixture()
        with patch.object(prepare, 'DOCKER_BLOB', prepare.blob_sha(source.encode())):
            result = prepare.prepare_dockerfile(source, candidate=True, jobs=4)
        self.assertIn('--patch-fibers /root/php-src/Zend/zend_fibers.c', result)
        self.assertIn('ASYNCIFY_IGNORE_INDIRECT=0', result)
        self.assertIn('ASYNCIFY_ONLY=[]', result)
        self.assertIn('make -j4', result)

    def test_changed_anchor_rejected_even_for_otherwise_accepted_recipe(self):
        source = self.fixture().replace('emmake make -j14', 'emmake make -j100')
        with patch.object(prepare, 'DOCKER_BLOB', prepare.blob_sha(source.encode())):
            with self.assertRaisesRegex(ValueError, 'Patch context changed'):
                prepare.prepare_dockerfile(source, candidate=False, jobs=2)


if __name__ == '__main__':
    unittest.main()

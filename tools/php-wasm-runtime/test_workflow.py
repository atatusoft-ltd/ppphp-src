"""Guard checkpoint ordering and executable shell/report admission in the workflow."""
import json
import re
import shlex
import subprocess
import tempfile
import unittest
from pathlib import Path

import native


class WorkflowTests(unittest.TestCase):
    def setUp(self):
        self.workflow = (native.HERE.parent.parent / '.github/workflows/php-wasm-rebuild.yml').read_text()
        self.job = self.workflow.split('\n  source-built:', 1)[1].split('\n  rebuild:', 1)[0]
        # Inspect this repository's fixed step indentation, not arbitrary YAML.
        self.steps = dict(re.findall(r'^      - name: ([^\n]+)\n(.*?)(?=^      - |\Z)', self.job, re.M | re.S))

    def test_completed_build_is_retained_before_the_next_and_full_comparison_remains(self):
        names = list(self.steps)
        required = ['Retain verified downloads before compilation',
                    'Build and test clean candidate 1',
                    'Preserve clean candidate 1 checkpoint links and permissions',
                    'Retain clean candidate 1 outputs and provenance',
                    'Build and test clean candidate 2',
                    'Preserve clean candidate 2 checkpoint links and permissions',
                    'Retain clean candidate 2 outputs and provenance',
                    'Compare complete independent clean builds',
                    'Retain full comparison separately from build checkpoints']
        indexes = [names.index(name) for name in required]
        self.assertEqual(indexes, sorted(indexes))
        self.assertIn('timeout-minutes: 240', self.job)
        for iteration in (1, 2):
            build = self.steps[f'Build and test clean candidate {iteration}']
            for command in ['source-build.py build --clean', 'verify-built.mjs',
                            'run-fiber-contract.mjs', '--suite native-contract']:
                self.assertIn(command, build)
            self.assertNotIn('8_4_23', build)
            upload = self.steps[f'Retain clean candidate {iteration} outputs and provenance']
            self.assertIn('always()', upload)
            self.assertIn(f'build-{iteration}', upload)
            for suffix in ['candidate/', 'native-checkpoint.tar', 'execution.json',
                           'context/*.Dockerfile', 'context/acquisitions.json',
                           'compatibility/report.json', 'fiber-contract/report.json', 'native-contract/report.json']:
                self.assertIn(f'/tmp/ppphp-source-build-{iteration}/' + suffix, upload)
        for name in required[4:]:
            self.assertIn("inputs.clean_builds == '2'", self.steps[name])
        comparison = self.steps[required[7]]
        self.assertNotIn('always()', comparison)
        self.assertIn('source-build.py compare --first /tmp/ppphp-source-build-1 --second /tmp/ppphp-source-build-2', comparison)

    def test_embedded_shell_scripts_parse(self):
        for body in self.steps.values():
            for script in re.findall(r'^        run: \|\n((?:^          .*\n)+)', body, re.M):
                result = subprocess.run(['bash', '-n'], input=script, text=True, capture_output=True, timeout=5)
                self.assertEqual(result.returncode, 0, result.stderr)

    def test_workflow_hash_admission_requires_accepted_candidate_identity(self):
        for iteration in (1, 2):
            body = self.steps[f'Build and test clean candidate {iteration}']
            line = next(line.strip() for line in body.splitlines() if line.strip().startswith('wasm_sha256='))
            command = shlex.split(line[len('wasm_sha256=$('):-1])
            with tempfile.TemporaryDirectory() as tmp:
                path = Path(tmp) / 'report.json'
                command[-1] = str(path)
                valid = {'profile': 'candidate', 'accepted': True, 'artifact': {'sha256': 'a' * 64}}
                for changes, admitted in [({}, True), ({'accepted': False}, False),
                                          ({'profile': 'baseline'}, False),
                                          ({'artifact': {'sha256': 'invalid'}}, False), ({'artifact': {}}, False)]:
                    path.write_text(json.dumps({**valid, **changes}))
                    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
                    self.assertEqual(result.returncode == 0, admitted)
                    self.assertEqual(result.stdout, 'a' * 64 if admitted else '')


if __name__ == '__main__':
    unittest.main()

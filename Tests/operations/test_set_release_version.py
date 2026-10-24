#!/usr/bin/env python3
"""Run scripts/set-release-version against copies of the real citation files."""
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SetReleaseVersionTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        for path in ['scripts/set-release-version', 'CITATION.cff', 'views/about.html']:
            (self.tmp / path).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / path, self.tmp / path)

    def run_script(self, *args):
        return subprocess.run([str(self.tmp / 'scripts/set-release-version'), *args], text=True, capture_output=True)

    def test_sets_version_date_and_citation_year(self):
        result = self.run_script('4.1', '2027-01-15')
        self.assertEqual(result.returncode, 0, result.stderr)
        citation = (self.tmp / 'CITATION.cff').read_text()
        self.assertIn('\nversion: "4.1"\n', citation)
        self.assertIn('\ndate-released: 2027-01-15\n', citation)
        self.assertIn('Kass Youssef, Joseph. 2027. SimpleMappr (version 4.1)', (self.tmp / 'views/about.html').read_text())

    def test_bad_input_and_reworded_files_change_nothing(self):
        before = (self.tmp / 'CITATION.cff').read_text()
        for args in [('v4.1', '2027-01-15'), ('4.1', '15/01/2027'), ('4.1',)]:
            self.assertNotEqual(self.run_script(*args).returncode, 0, args)
        self.assertEqual((self.tmp / 'CITATION.cff').read_text(), before)
        about = self.tmp / 'views/about.html'
        about.write_text(about.read_text().replace('Kass Youssef, Joseph.', 'Someone Else.'))
        result = self.run_script('4.1', '2027-01-15')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('expected exactly one line', result.stderr)


if __name__ == '__main__':
    unittest.main()

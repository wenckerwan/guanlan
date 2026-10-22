import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('stability', ROOT / 'tools' / 'test_stability.py')
stability = importlib.util.module_from_spec(spec)
spec.loader.exec_module(stability)


class StabilitySnapshotTests(unittest.TestCase):
    def git(self, root, *args):
        return subprocess.run(['git', *args], cwd=root, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              text=True).stdout

    def test_snapshot_detects_rewrite_of_already_dirty_tracked_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.git(root, 'init', '-q')
            self.git(root, 'config', 'user.name', 'Fixture')
            self.git(root, 'config', 'user.email', 'fixture@example.invalid')
            tracked = root / 'tracked.txt'
            tracked.write_text('baseline\n')
            self.git(root, 'add', 'tracked.txt')
            self.git(root, 'commit', '-qm', 'fixture')
            tracked.write_text('already dirty\n')
            before = stability.snapshot(root)
            tracked.write_text('rewritten while dirty\n')
            self.assertNotEqual(before, stability.snapshot(root))

    def test_snapshot_ignores_ignored_file_rewrites(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.git(root, 'init', '-q')
            self.git(root, 'config', 'user.name', 'Fixture')
            self.git(root, 'config', 'user.email', 'fixture@example.invalid')
            (root / '.gitignore').write_text('ignored.log\n')
            self.git(root, 'add', '.gitignore')
            self.git(root, 'commit', '-qm', 'fixture')
            ignored = root / 'ignored.log'
            ignored.write_text('one\n')
            before = stability.snapshot(root)
            ignored.write_text('two\n')
            self.assertEqual(before, stability.snapshot(root))


if __name__ == '__main__':
    unittest.main()

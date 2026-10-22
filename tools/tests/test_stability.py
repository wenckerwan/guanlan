import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('stability', ROOT / 'tools' / 'test_stability.py')
stability = importlib.util.module_from_spec(spec)
spec.loader.exec_module(stability)


class StabilitySnapshotTests(unittest.TestCase):
    def test_backend_keeps_php_extensions_except_for_mailer_socket_double(self):
        with patch.object(stability, 'snapshot', return_value=('unchanged', ())), \
                patch.object(stability, 'run') as run, \
                patch('sys.argv', ['test_stability.py', '--suite', 'backend']):
            stability.main()
        commands = [call.args[0] for call in run.call_args_list]
        for name in stability.PHP_TESTS:
            command = next(command for command in commands
                           if Path(command[-1]).name == f'{name}.php')
            self.assertEqual('-n' in command, name == 'MailerTransportTest', name)
        verifier = next(command for command in commands
                        if any('verify-dataset.php' in arg for arg in command))
        self.assertNotIn('-n', verifier)

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

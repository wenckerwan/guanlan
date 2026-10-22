"""Read-only offline regression suite. Does not import datasets or contact production."""
import argparse
import hashlib
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
PHP_TESTS = ('AccountIdTest', 'AIAnalysisTest', 'AiOutboundPolicyTest', 'Bugs1022Test',
             'ContentStatusTest', 'DatasetManifestVerifierTest', 'MailerTransportTest',
             'MistakeAccessTest', 'MistakeImportAccessTest', 'SecurityBoundaryTest', 'VisitWindowTest')


def status(root=ROOT):
    """Return the existing worktree status so this entrypoint cannot hide writes."""
    result = subprocess.run(['git', 'status', '--porcelain=v1', '--untracked-files=all'], cwd=root,
                            text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError('git status is required for the read-only stability guard')
    return result.stdout


def _git_paths(root, *args):
    result = subprocess.run(['git', *args], cwd=root, check=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return {os.fsdecode(item) for item in result.stdout.split(b'\0') if item}


def _file_fingerprint(path):
    try:
        stat = path.lstat()
    except FileNotFoundError:
        return ('missing',)
    mode = stat.st_mode & 0o7777
    if path.is_symlink():
        return ('symlink', mode, os.readlink(path))
    if not path.is_file():
        return ('other', mode)
    content = path.read_bytes()
    return ('file', mode, len(content), hashlib.sha256(content).hexdigest())


def snapshot(root=ROOT):
    """Capture status and content fingerprints for tracked/nonignored files."""
    root = Path(root).resolve()
    paths = _git_paths(root, 'ls-files', '-z') | _git_paths(root, 'ls-files', '--others', '--exclude-standard', '-z')
    files = tuple((name, _file_fingerprint(root / name)) for name in sorted(paths))
    return status(root), files


def run(command, cwd=ROOT):
    subprocess.run(command, cwd=cwd, check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--node', default='node')
    parser.add_argument('--suite', choices=['all', 'frontend', 'backend', 'release'], default='all')
    args = parser.parse_args()
    before = snapshot()
    try:
        if args.suite in ('all', 'frontend'):
            web = ROOT / 'apps/web'
            run([args.node, '--test', '--test-concurrency=1',
                 *[str(p.relative_to(web)) for p in sorted((web / 'tests').glob('*.test.mjs'))]], cwd=web)
        if args.suite in ('all', 'backend'):
            for name in PHP_TESTS:
                run([args.php, '-n', '-d', 'zend.assertions=1', '-d', 'assert.exception=1',
                     str(ROOT / f'apps/api/tests/{name}.php')])
            run([args.php, '-n', str(ROOT / 'apps/api/bin/verify-dataset.php'), str(ROOT)])
        if args.suite in ('all', 'backend', 'release'):
            run([sys.executable, '-X', 'utf8', str(ROOT / 'tools/tests/test_stability.py')])
            run([sys.executable, '-X', 'utf8', str(ROOT / 'tools/deploy/test_release.py')])
    finally:
        after = snapshot()
        if after != before:
            raise RuntimeError('read-only stability suite changed the working tree')
    print('Offline stability regressions passed; browser, database integration and deployment are separate checks.')


if __name__ == '__main__':
    main()

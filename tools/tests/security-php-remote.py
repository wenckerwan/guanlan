"""Run local, dependency-free PHP regressions in the existing PHP container.

Only sends source via stdin; does not write remote files or access application data.
"""
import base64
from pathlib import Path
import re
import subprocess
import sys

root = Path(__file__).resolve().parents[2]
test = root / 'apps/api/tests/SecurityBoundaryTest.php'
source = test.read_text(encoding='utf-8')
for match in list(re.finditer(r"require __DIR__ \. '([^']+)';", source)):
    dependency = (test.parent / match[1].lstrip('/')).resolve()
    if '--baseline' in sys.argv:
        code = subprocess.check_output(['git', 'show', 'HEAD:' + dependency.relative_to(root).as_posix()], cwd=root).decode('utf-8').removeprefix('<?php')
    else:
        code = dependency.read_text(encoding='utf-8').removeprefix('<?php')
    encoded = base64.b64encode(code.encode()).decode()
    source = source.replace(match[0], f"eval(base64_decode('{encoded}'));")
result = subprocess.run(
    ['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8',
     'root-189', 'docker exec -i guanlan-api-1 php'],
    input=source.encode(), capture_output=True, timeout=45,
)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
sys.exit(result.returncode)

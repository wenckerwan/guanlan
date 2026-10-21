// Execute dependency-free protocol regressions using PHP in the existing API container.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const relative = 'apps/api/src/Support/Mailer.php';
const mailer = process.argv.includes('--baseline')
  ? cp.execFileSync('git', ['show', 'HEAD:' + relative], { cwd: root, encoding: 'utf8' })
  : fs.readFileSync(path.join(root, relative), 'utf8');
let test = fs.readFileSync(path.join(root, 'apps/api/tests/MailerTransportTest.php'), 'utf8');
test = test.replace("require __DIR__ . '/../src/Support/Mailer.php';", `eval(base64_decode('${Buffer.from(mailer.replace(/^<\?php\s*/, '')).toString('base64')}'));`);
const ssh = process.env.SSH_EXE || (process.platform === 'win32'
  ? 'C:/Users/Administrator/.cache/codex-runtimes/codex-primary-runtime/dependencies/native/git/usr/bin/ssh.exe'
  : 'ssh');
const result = cp.spawnSync(ssh, ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'root-189', 'docker exec -i guanlan-api-1 php -n'], { input: test, encoding: 'utf8', timeout: 45000 });
process.stdout.write(result.stdout || ''); process.stderr.write(result.stderr || '');
if (result.error) console.error(result.error.message);
process.exit(result.status ?? 1);

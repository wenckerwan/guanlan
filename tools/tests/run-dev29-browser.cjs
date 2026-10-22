// Own the preview process so local and CI verification use the same built artifact.
const { spawn } = require('node:child_process');
const path = require('node:path');
const port = process.env.DEV29_PORT || '3039';
const base = `http://127.0.0.1:${port}`;
const delay = () => new Promise(resolve => setTimeout(resolve, 250));
async function main() {
  // Refuse to test an unrelated preview already occupying this port.
  try { await fetch(base); throw Error(`Preview port ${port} is already in use`); }
  catch (error) { if (error.message.includes('already in use')) throw error; }
  const server = spawn(process.execPath, ['.output/server/index.mjs'], {
    cwd: path.resolve(__dirname, '../../apps/web'),
    env: { ...process.env, PORT: port, HOST: '127.0.0.1' }, stdio: 'inherit',
  });
  let startupError;
  server.on('error', error => { startupError = error; });
  try {
    let ready = false;
    for (let attempt = 0; attempt < 120; attempt++) {
      if (startupError) throw startupError;
      if (server.exitCode !== null) throw Error('Preview exited before readiness');
      try { ready = (await fetch(base)).ok; } catch {}
      if (ready) break;
      await delay();
    }
    if (!ready) throw Error('Preview did not become ready');
    await new Promise((resolve, reject) => {
      const test = spawn(process.execPath, [path.join(__dirname, 'dev29-browser.cjs'), base], { env: process.env, stdio: 'inherit' });
      test.on('error', reject);
      test.on('exit', code => code === 0 ? resolve() : reject(Error(`Browser suite exited ${code}`)));
    });
  } finally { server.kill(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });

// Own the preview process so local and CI verification use the same built artifact.
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const path = require('node:path');
const { createFixture, runBrowserChecks } = require('./dev29-browser.cjs');
const port = process.env.DEV29_PORT || '3039';
const base = `http://127.0.0.1:${port}`;
const delay = () => new Promise(resolve => setTimeout(resolve, 250));
async function main() {
  // Refuse to test an unrelated preview already occupying this port.
  try { await fetch(base); throw Error(`Preview port ${port} is already in use`); }
  catch (error) { if (error.message.includes('already in use')) throw error; }
  const fixture = createFixture();
  let server;
  try {
    await once(fixture.api.listen(0, '127.0.0.1'), 'listening');
    server = spawn(process.execPath, ['.output/server/index.mjs'], {
      cwd: path.resolve(__dirname, '../../apps/web'),
      env: {
        ...process.env, PORT: port, HOST: '127.0.0.1',
        NUXT_API_INTERNAL_BASE: `http://127.0.0.1:${fixture.api.address().port}/api/v1`,
        NUXT_PUBLIC_API_BASE: '/api/v1',
        NUXT_SESSION_ORIGIN: base,
      },
      stdio: 'inherit',
    });
    let startupError;
    server.on('error', error => { startupError = error; });
    let ready = false;
    for (let attempt = 0; attempt < 120; attempt++) {
      if (startupError) throw startupError;
      if (server.exitCode !== null) throw Error('Preview exited before readiness');
      try { ready = (await fetch(base)).ok; } catch {}
      if (ready) break;
      await delay();
    }
    if (!ready) throw Error('Preview did not become ready');
    await runBrowserChecks(base, fixture);
    if (fixture.errors.length) throw new AggregateError(fixture.errors, 'Mock API failed');
  } finally {
    server?.kill();
    await new Promise(resolve => fixture.api.close(resolve));
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });

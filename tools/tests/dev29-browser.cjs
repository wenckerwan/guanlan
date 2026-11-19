// Exercise the built Nuxt app and its BFF against a loopback-only upstream.
const assert = require('node:assert/strict');
const { createServer } = require('node:http');
const dataset = require('../../storage/history/history.v1.json');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const tokens = { A: 'a'.repeat(64), B: 'b'.repeat(64) };
const accounts = {
  A: { id: 1, displayName: 'Fixture A', role: 'user', status: 'active' },
  B: { id: 2, displayName: 'Fixture B', role: 'user', status: 'active' },
};

function createFixture() {
  const fixture = { expired: false, notes: { A: [], B: [] }, writes: [], errors: [] };
  let nextId = 1;
  fixture.api = createServer(async (req, res) => {
    const send = (status, payload) => {
      res.writeHead(status, { 'content-type': 'application/json' });
      res.end(JSON.stringify(payload));
    };
    const reply = data => send(200, { data });
    const pathname = new URL(req.url, 'http://localhost').pathname;
    const method = req.method;
    const account = Object.keys(tokens).find(key => req.headers.authorization === `Bearer ${tokens[key]}`);
    try {
      if (pathname === '/api/v1/home') return reply({ hotspots: [], documents: [], subjects: [] });
      if (pathname === '/api/v1/auth/me') {
        return account ? reply({ user: accounts[account] }) : send(401, { message: 'Sign in required' });
      }
      if (pathname === '/api/v1/history/events') {
        return account ? reply(dataset) : send(401, { message: 'Sign in required' });
      }
      if (!pathname.startsWith('/api/v1/study/')) return send(404, { message: 'No fixture for this route' });
      if (!account || fixture.expired) return send(401, { message: 'Session expired' });
      const [, resource, id] = pathname.match(/^\/api\/v1\/study\/([^/]+)(?:\/([^/]+))?$/) || [];
      if (!['notes', 'favorites', 'progress'].includes(resource)) return send(404, { message: 'Unknown study route' });
      if (method === 'GET') return reply(resource === 'notes' ? fixture.notes[account] : []);
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      const body = chunks.length ? JSON.parse(Buffer.concat(chunks).toString()) : null;
      fixture.writes.push({ account, resource, body });
      if (resource !== 'notes') return reply({});
      const notes = fixture.notes[account];
      if (method === 'POST') {
        const row = { ...body, id: nextId++ };
        notes.push(row);
        return reply(row);
      }
      const index = notes.findIndex(row => String(row.id) === id);
      if (index === -1) return send(404, { message: 'Unknown note' });
      if (method === 'PATCH') return reply(Object.assign(notes[index], body));
      if (method === 'DELETE') return reply(notes.splice(index, 1)[0]);
      return send(405, { message: 'Unexpected note operation' });
    } catch (error) {
      fixture.errors.push(error);
      send(500, { message: 'Mock API failed' });
    }
  });
  return fixture;
}

async function until(label, condition) {
  for (let n = 0; n < 100; n++) {
    if (await condition()) return;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw Error(`Timed out: ${label}`);
}

async function runBrowserChecks(base, fixture) {
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined });
  try {
    const context = await browser.newContext();
    const signIn = account => context.addCookies([
      { name: 'guanlan.session', value: tokens[account], url: base, httpOnly: true },
    ]);
    await signIn('A');
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    const note = page.locator('label.hr-note textarea');
    const status = page.locator('label.hr-note small');
    const openNote = async () => {
      await page.waitForSelector('.hr-reader');
      await page.getByRole('searchbox').fill('虎门');
      await page.locator('.hr-table-scroll a').first().click();
      await note.waitFor();
    };
    const editNote = async text => { await note.fill(text); await note.blur(); };
    const saved = text => fixture.notes.A.some(row => row.content === text);
    const pending = () => until('pending note status', async () => (await status.innerText()).includes('待同步'));
    const refocus = () => page.evaluate(() => window.dispatchEvent(new Event('focus')));

    await page.goto(`${base}/history`);
    await openNote();
    await editNote('在线原始笔记');
    await until('online save', () => saved('在线原始笔记'));

    await context.setOffline(true);
    await editNote('离线修改笔记');
    await pending();
    assert.ok(saved('在线原始笔记'));
    await context.setOffline(false);
    await until('reconnected save', () => saved('离线修改笔记'));
    await until('synced note status', async () => (await status.innerText()).includes('已同步到账号'));
    assert.equal(await note.inputValue(), '离线修改笔记');

    fixture.expired = true;
    await editNote('登录失效后笔记');
    await page.waitForURL('**/login?**');
    assert.ok(saved('离线修改笔记'));
    fixture.expired = false;
    await page.goto(`${base}/history`);
    await until('save after reauthentication', () => saved('登录失效后笔记'));
    await openNote();

    // Prevent sync temporarily while allowing the BFF to verify the new session.
    const studyRequests = '**/api/v1/study/**';
    const disconnectStudy = route => route.abort('internetdisconnected');
    await context.route(studyRequests, disconnectStudy);
    const privateDraft = 'A账号待同步私有草稿';
    await editNote(privateDraft);
    await pending();
    assert.ok(saved('登录失效后笔记'));
    await signIn('B');
    await context.unroute(studyRequests, disconnectStudy);
    const bSession = page.waitForResponse(async response => {
      if (!response.ok() || !response.url().endsWith('/auth/me')) return false;
      return (await response.json()).data.user.id === accounts.B.id;
    });
    await refocus();
    await bSession;
    await openNote();
    await until('account B sees no draft', async () => (await note.inputValue()) === '');
    assert.equal(fixture.notes.B.length, 0);
    assert.ok(!fixture.writes.some(write => write.account === 'B' && write.resource === 'notes'));

    await signIn('A');
    await refocus();
    await until('draft replayed only for its owner', () => saved(privateDraft));
    assert.equal(fixture.notes.B.length, 0);
    assert.deepEqual(errors, []);
    console.log('PASS history online/offline saves, 401 recovery and account isolation through the BFF');
    await context.close();
  } finally {
    await browser.close();
  }
}

module.exports = { createFixture, runBrowserChecks };

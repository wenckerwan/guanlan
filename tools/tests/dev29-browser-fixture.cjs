// Real loopback upstream for the production BFF, including SSR and account checks.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const dataset = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../storage/history/history.v1.json'), 'utf8'));
const password = 'fixture-password';
const tokens = { A: 'a'.repeat(64), B: 'b'.repeat(64) };
const user = account => ({ id: account === 'B' ? 2 : 1, name: `Fixture ${account}`, displayName: `Fixture ${account}`, role: 'student', code: 'fixture', mistakeCode: 'fixture' });
async function startFixture() {
  const state = { offline: false, expired: false, notes: [], favorites: [], bNotes: [], bFavorites: [], bWrites: [], logins: [] };
  const server = http.createServer(async (req, res) => {
    const reply = (data, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ data })); };
    try {
      const url = new URL(req.url, 'http://fixture');
      let raw = ''; for await (const chunk of req) raw += chunk;
      const body = raw ? JSON.parse(raw) : null;
      if (url.pathname === '/__fixture') {
        if (req.method === 'POST') { assert.ok(['offline', 'expired'].every(key => body[key] === undefined || typeof body[key] === typeof state[key])); for (const key of ['offline', 'expired']) if (body[key] !== undefined) state[key] = body[key]; }
        return reply(state);
      }
      const route = url.pathname.replace(/^\/api\/v1/, '');
      if (route === '/auth/login' && req.method === 'POST') {
        const account = body?.email === 'fixture-a@example.test' ? 'A' : body?.email === 'fixture-b@example.test' ? 'B' : null;
        if (!account || body.password !== password) return reply({ message: 'Invalid fixture credentials' }, 401);
        state.logins.push(account);
        return reply({ user: user(account), token: tokens[account] });
      }
      const account = Object.keys(tokens).find(key => req.headers.authorization === `Bearer ${tokens[key]}`);
      if (route === '/auth/me') return account ? reply({ user: user(account) }) : reply(null, 401);
      if (route === '/auth/logout') return reply(null);
      if (route === '/history/events') return reply(dataset);
      if (!account) return reply(null, 401);
      if (route.includes('/study/')) {
        const isB = account === 'B';
        if (isB && req.method !== 'GET') state.bWrites.push(body);
        if (!isB && state.offline) return reply(null, 503);
        if (!isB && state.expired) return reply(null, 401);
        const notes = isB ? state.bNotes : state.notes;
        if (route.includes('/notes')) {
          if (req.method === 'POST') notes.push({ id: notes.length + 1, ...body });
          if (req.method === 'PATCH') Object.assign(notes.find(note => String(note.id) === route.split('/').at(-1)), body);
          if (req.method === 'DELETE') { const index = notes.findIndex(note => String(note.id) === route.split('/').at(-1)); if (index >= 0) notes.splice(index, 1); }
          return reply(notes);
        }
        if (route.includes('/favorites')) {
          if (!isB && req.method === 'PUT') { state.favorites = state.favorites.filter(item => item.targetId !== body.targetId); if (body.favorited) state.favorites.push({ id: 1, ...body }); }
          return reply(isB ? state.bFavorites : state.favorites);
        }
        return reply([]);
      }
      return reply([]);
    } catch (error) { console.error('Fixture request failed:', error); if (!res.headersSent) reply(null, 500); else res.end(); }
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  return { url: `http://127.0.0.1:${server.address().port}`, close: () => new Promise((resolve, reject) => { server.close(error => error ? reject(error) : resolve()); server.closeAllConnections(); }) };
}
module.exports = { startFixture, password };

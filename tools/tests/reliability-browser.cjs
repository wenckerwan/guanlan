// 对真实前端和浏览器存储执行隔离回归；所有API由本地测试桩响应。
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const main = process.env.RELIABILITY_MAIN_URL || 'http://127.0.0.1:3311';
const mayuan = process.env.RELIABILITY_MAYUAN_URL || 'http://127.0.0.1:3312';
const dataset = JSON.parse(fs.readFileSync(path.join(root, 'apps/api/storage/history/history.v1.json'), 'utf8'));
const content = JSON.parse(fs.readFileSync(path.join(root, 'vendor/mayuan/content/seed.json'), 'utf8'));
const waitUntil = async (check, description) => { for (let i = 0; i < 100; ++i) { if (await check()) return; await new Promise(resolve => setTimeout(resolve, 100)); } throw Error(`timeout: ${description}`); };

(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.RELIABILITY_CHROMIUM_PATH ? { executablePath: process.env.RELIABILITY_CHROMIUM_PATH } : {}) });
  const errors = [], writes = [], verified = [];
  let denyAWrites = false;
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const remote = { A: { notes: [], favorites: [] }, B: { notes: [], favorites: [] } };
    await context.route('**/api/v1/**', async route => {
      const request = route.request(), url = new URL(request.url()), token = (request.headers().authorization || '').replace('Bearer ', '');
      let data = [];
      if (url.pathname.endsWith('/auth/me')) { verified.push(token); data = { user: { id: token === 'A' ? 1 : 2, role: 'user' } }; }
      else if (url.pathname.endsWith('/history/events')) data = dataset;
      else {
        const owner = remote[token];
        if (!owner) return route.fulfill({ status: 401, json: {} });
        if (request.method() !== 'GET') {
          const body = request.postDataJSON(); writes.push({ token, path: url.pathname, body });
          if (token === 'A' && denyAWrites) return route.fulfill({ status: 503, json: { message: 'isolated offline write' } });
          if (url.pathname.endsWith('/notes')) owner.notes.push({ id: owner.notes.length + 1, ...body });
          if (url.pathname.endsWith('/favorites')) owner.favorites.push({ id: owner.favorites.length + 1, ...body });
        }
        data = url.pathname.endsWith('/notes') ? owner.notes : url.pathname.endsWith('/favorites') ? owner.favorites : [];
      }
      await route.fulfill({ json: { data } });
    });
    const cookie = value => context.addCookies([{ name: 'guanlan.token', value, url: main }]);
    await cookie('A');
    const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
    const eventId = dataset.events.find(e => e.publication !== 'pending').id;
    await page.goto(`${main}/history/?event=${encodeURIComponent(eventId)}`); await page.getByRole('heading', { name: '在时间中，读懂历史' }).waitFor();
    denyAWrites = true;
    await page.getByRole('textbox', { name: '事件个人笔记' }).fill('A private pending note');
    await page.getByRole('button', { name: '收藏', exact: true }).click();
    await waitUntil(() => page.evaluate(() => new Promise(resolve => {
      const r = indexedDB.open('history-study-guanlan-v2:user:1', 1);
      r.onsuccess = () => { const db = r.result, get = db.transaction('study').objectStore('study').get('pending-sync'); get.onsuccess = () => { db.close(); resolve(String(JSON.stringify(get.result)).includes('A private pending note')); }; };
    })), 'real UI offline note retained');
    await page.evaluate(async id => {
      const state = { version: 1, bookmarks: [id], notes: { [id]: 'A private pending note' }, attempts: [], view: null };
      for (const name of ['history-study-guanlan-v1']) {
        await new Promise((resolve, reject) => {
          const request = indexedDB.open(name, 1); request.onupgradeneeded = () => request.result.createObjectStore('study'); request.onerror = reject;
          request.onsuccess = () => { const db = request.result, tx = db.transaction('study', 'readwrite'); tx.objectStore('study').put(state, 'state'); tx.objectStore('study').put(state, 'pending-sync'); tx.oncomplete = () => { db.close(); resolve(); }; };
        });
      }
    }, eventId);
    await cookie('B'); await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await waitUntil(() => verified.includes('B'), 'fresh B verification');
    await page.getByRole('heading', { name: '在时间中，读懂历史' }).waitFor();
    assert.equal(writes.some(r => r.token === 'B' && JSON.stringify(r.body).includes('A private')), false);
    const bNotes = await page.evaluate(() => new Promise(resolve => {
      const r = indexedDB.open('history-study-guanlan-v2:user:2', 1);
      r.onsuccess = () => { const db = r.result; const get = db.transaction('study').objectStore('study').get('state'); get.onsuccess = () => { db.close(); resolve(get.result?.notes); }; };
    }));
    assert.deepEqual(bNotes, {});
    // 另一个标签页也须使用B的分区，切回A时只允许A重放自己的队列。
    const tab = await context.newPage(); tab.on('pageerror', e => errors.push(e.message));
    await tab.goto(`${main}/history/`); await tab.getByRole('heading', { name: '在时间中，读懂历史' }).waitFor();
    denyAWrites = false;
    const previousPosts = writes.filter(r => r.token === 'A' && r.body?.content === 'A private pending note').length;
    await cookie('A'); await tab.evaluate(() => window.dispatchEvent(new StorageEvent('storage', { key: 'guanlan.user' })));
    await waitUntil(() => writes.filter(r => r.token === 'A' && r.body?.content === 'A private pending note').length > previousPosts, 'A reconnect replay');
    assert.equal(writes.some(r => r.token === 'B' && r.body?.content === 'A private pending note'), false);
    await page.locator('.history-host__legacy summary').click();
    const legacyDownload = page.waitForEvent('download'); await page.getByRole('button', { name: '导出旧记录' }).click();
    const legacy = await legacyDownload; assert.ok(fs.readFileSync(await legacy.path(), 'utf8').includes('A private pending note'));
    await context.close();
    console.log('PASS history: A/B partitions, fresh identity, tab switch, reconnect and explicit legacy export');

    const ma = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    // 生产由主站网关提供此脚本；独立子应用测试服务器没有该资源。
    await ma.route('**/visit-tracker.js', route => route.fulfill({ contentType: 'application/javascript', body: '' }));
    await ma.route('**/api/v2/**', route => route.fulfill({ json: { data: route.request().url().endsWith('/session') ? { mode: 'local' } : content } }));
    const p = await ma.newPage(); p.on('pageerror', e => errors.push(e.message));
    await p.addInitScript(() => {
      if (sessionStorage.getItem('test-seeded') || localStorage.getItem('mayuan-v2-local-state') !== null) return;
      localStorage.setItem('mayuan-v2-local-state', '{broken'); sessionStorage.setItem('test-seeded', '1');
    });
    await p.goto(`${mayuan}/mayuan/`);
    await p.locator('.storage-warning').waitFor();
    assert.equal(await p.evaluate(() => localStorage.getItem('mayuan-v2-local-state')), '{broken');
    const rawEvent = p.waitForEvent('download'); await p.getByRole('button', { name: '下载损坏的原始记录' }).click();
    assert.equal(fs.readFileSync(await (await rawEvent).path(), 'utf8'), '{broken');
    await p.getByRole('button', { name: '备份原记录并重试保存' }).click(); await p.locator('.storage-warning').waitFor({ state: 'hidden' });
    assert.ok(await p.evaluate(() => Object.keys(localStorage).some(key => key.includes(':recovery:') && localStorage.getItem(key) === '{broken')));
    const idle = await ma.newPage(); idle.on('pageerror', e => errors.push(e.message));
    await idle.goto(`${mayuan}/mayuan/`); await idle.getByRole('button', { name: /复习记录/ }).waitFor();
    await p.bringToFront();
    // 浏览器存储真实抛出配额错误，验证页面仍可判题和导出内存结果。
    await p.evaluate(() => { window.__setItem = Storage.prototype.setItem; Storage.prototype.setItem = function(k, v) { if (k === 'mayuan-v2-local-state') throw new DOMException('quota', 'QuotaExceededError'); return window.__setItem.call(this, k, v); }; });
    await p.getByRole('button', { name: /原创练习/ }).click(); await p.locator('.options input').first().check();
    await p.getByRole('button', { name: '提交并核验', exact: true }).click(); await p.locator('.storage-warning').waitFor();
    const savedEvent = p.waitForEvent('download'); await p.getByRole('button', { name: '导出当前记录' }).click();
    const saved = JSON.parse(fs.readFileSync(await (await savedEvent).path(), 'utf8'));
    assert.equal(saved.state.attempts.length, 1);
    await p.evaluate(() => { Storage.prototype.setItem = window.__setItem; });
    await p.getByRole('button', { name: '重试保存', exact: true }).click(); await p.locator('.storage-warning').waitFor({ state: 'hidden' });
    await idle.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    await idle.close();
    await p.reload(); await p.getByRole('button', { name: /复习记录/ }).click();
    assert.equal(await p.evaluate(() => JSON.parse(localStorage.getItem('mayuan-v2-local-state')).attempts.length), 1);
    await p.setViewportSize({ width: 390, height: 844 });
    assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.deepEqual(errors, []);
    await ma.close();
    console.log('PASS mayuan: damaged raw retained/exported, backup before replacement, quota warning, memory export, retry, idle-tab close and refresh');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

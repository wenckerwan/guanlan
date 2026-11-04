// Production preview with a real fixture BFF upstream; never inject credentials or mock auth.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { password } = require('./dev29-browser-fixture.cjs');
const base = process.argv[2] || 'http://localhost:3039';
const fixture = process.env.DEV29_FIXTURE_URL;
assert.ok(fixture, 'Run through run-dev29-browser.cjs to configure the fixture BFF upstream');
const until = async (fn) => { for (let n=0;n<100;n++) { if(await fn()) return; await new Promise(r=>setTimeout(r,100)); } throw Error('fixture assertion timed out'); };
const state = async (patch) => {
  const response = await fetch(fixture + '/__fixture', patch ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(patch) } : {});
  assert.equal(response.status, 200);
  return (await response.json()).data;
};
(async () => {
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined, headless: true });
  try {
    const context = await browser.newContext();
    const errors=[];
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    assert.equal((await context.request.get(base + '/api/v1/auth/me')).status(), 401, 'Fixture must reject an anonymous session');
    const login = async (account, redirect) => {
      // Keep login on a Nuxt route so the response remains readable before full navigation.
      await page.goto(base + '/login?redirect=%2Fme');
      await page.getByLabel('邮箱', { exact: true }).fill(`fixture-${account.toLowerCase()}@example.test`);
      await page.getByLabel('密码', { exact: true }).fill(password);
      const response = page.waitForResponse(r => new URL(r.url()).pathname === '/api/v1/auth/login' && r.request().method() === 'POST')
        .then(async response => ({ status: response.status(), payload: await response.json() }));
      await page.locator('.auth-form').getByRole('button', { name: '登录', exact: true }).click();
      const authenticated = await response;
      assert.equal(authenticated.status, 200);
      assert.equal(authenticated.payload.data.token, undefined, 'BFF must strip upstream credentials');
      await page.waitForURL(base + '/me');
      const cookie = (await context.cookies(base)).find(cookie => cookie.name === 'guanlan.session');
      assert.ok(cookie?.httpOnly, 'Login must issue a server-owned HttpOnly session');
      assert.equal(cookie.secure, true, 'Production cookie must remain Secure');
      assert.equal(cookie.sameSite, 'Lax');
      assert.equal((await context.cookies(base)).some(cookie => cookie.name === 'guanlan.token'), false);
      assert.equal(await page.evaluate(() => document.cookie.includes('guanlan.session')), false);
      await page.goto(base + redirect);
    };
    // AI analysis was deliberately retired; its old upload route is no longer a browser target.
    await login('A', '/history');
    // A hard reload verifies SSR restores via the same real BFF, not browser interception.
    await page.reload();
    await page.waitForSelector('.hr-reader').catch(async e=>{console.error(await page.locator('body').innerText());throw e;});
    const identity = await context.request.get(base + '/api/v1/auth/me');
    assert.equal(identity.status(), 200);assert.equal((await identity.json()).data.user.id, 1);
    console.log('PASS BFF login/HttpOnly/token stripping/SSR session restore');
    await page.getByRole('searchbox').fill('虎门');await page.locator('.hr-table-scroll a').first().click();
    const note=page.locator('.hr-note textarea');await note.fill('在线原始笔记');await note.blur();
    await until(async()=>(await state()).notes.some(n=>n.content==='在线原始笔记'));
    await state({offline:true});await context.setOffline(true);
    await until(async()=> (await page.locator('.hr-note small').innerText()).includes('待同步'));
    await note.fill('离线修改笔记');await note.blur();
    await until(async()=>(await page.locator('.hr-note small').innerText()).includes('待同步'));
    await state({offline:false});await context.setOffline(false);
    await until(async()=>(await state()).notes.some(n=>n.content==='离线修改笔记'));
    await until(async()=> (await page.locator('.hr-note small').innerText()).includes('已同步到账号'));
    await until(async()=>!(await note.isDisabled()));assert.equal(await note.inputValue(),'离线修改笔记');
    await state({expired:true});await note.fill('登录失效后笔记');await note.blur();
    await page.waitForURL('**/login?**');
    assert.equal((await state()).notes[0].content,'离线修改笔记');
    await state({expired:false});await login('A', '/history');await page.waitForSelector('.hr-reader');
    await until(async()=>(await state()).notes.some(n=>n.content==='登录失效后笔记'));
    // Another tab signs in via BFF while A has a pending draft in this tab.
    await page.getByRole('searchbox').fill('虎门');await page.locator('.hr-table-scroll a').first().click();
    await state({offline:true});await note.fill('A账号待同步私有草稿');await note.blur();
    await until(async()=>(await page.locator('.hr-note small').innerText()).includes('待同步'));
    const switchSession = async account => {
      const tab = await context.newPage();
      try {
        await tab.goto(base + '/login');
        const response = await tab.evaluate(async ({account,password}) => {
          const result = await fetch('/api/v1/auth/login', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({email:`fixture-${account.toLowerCase()}@example.test`,password}) });
          return {status:result.status,payload:await result.json()};
        }, {account,password});
        assert.equal(response.status,200);assert.equal(response.payload.data.token,undefined);
      } finally { await tab.close(); }
      await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
    };
    await switchSession('B');
    await until(async()=>await note.isVisible() && (await note.inputValue())==='');
    assert.equal((await state()).bNotes.length,0);
    assert.ok((await state()).bWrites.every(body=>!JSON.stringify(body).includes('A账号待同步私有草稿')));
    await state({offline:false});
    await switchSession('A');
    await until(async()=>(await state()).notes.some(n=>n.content==='A账号待同步私有草稿'));
    assert.deepEqual((await state()).logins,['A','A','B','A']);
    assert.deepEqual(errors,[]);
    console.log('PASS history offline edit/reconnect/401/pending replay/BFF account switch; no page errors');
    await context.close();
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

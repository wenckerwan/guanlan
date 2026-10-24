// Local production UI smoke test with intercepted API fixtures, never a live account.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const dataset = JSON.parse(fs.readFileSync(path.join(root, 'storage/history/history.v1.json'), 'utf8'));
const base = process.argv[2] || 'http://127.0.0.1:4188';
(async () => {
  const browser = await chromium.launch({channel:'msedge',headless:true});
  try {
    const context = await browser.newContext({viewport:{width:390,height:844}});
    await context.addCookies([{name:'guanlan.token',value:'dev30-fixture',url:base}]);
    const page = await context.newPage(); page.setDefaultTimeout(12000);
    const errors=[]; page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/api/v1/**', route => {
      const url=new URL(route.request().url());
      const data=url.pathname.endsWith('/auth/me')?{user:{id:1,name:'Fixture',role:'user'}}:url.pathname.endsWith('/history/events')?dataset:[];
      return route.fulfill({json:{data}});
    });
    await page.addInitScript(()=>Object.defineProperty(navigator,'clipboard',{value:{writeText:async()=>{throw new Error('denied')}}}));
    const id='p1-r001-e01';
    await page.goto(`${base}/history/?event=${id}`, {waitUntil:'domcontentloaded'});
    const lesson=page.locator(`#history-event-${id}`);
    await lesson.locator('.hr-content').waitFor({state:'visible'});
    await lesson.getByRole('button',{name:'复制引用',exact:true}).click();
    const fallback=lesson.getByRole('textbox',{name:'手动复制分享或引用'});
    await fallback.waitFor();
    assert.match(await fallback.inputValue(),new RegExp(`event=${id}`));
    assert.match(await fallback.inputValue(),/数据版本/);
    await page.reload();await lesson.locator('.hr-content').waitFor({state:'visible'});
    await page.getByRole('searchbox').pressSequentially('遵义会议',{delay:15});
    assert.equal(await page.getByRole('searchbox').inputValue(),'遵义会议');
    await page.locator('.hr-table-scroll a').first().click();
    await page.waitForURL(/event=p4-r055-e01/);
    await page.goBack();await lesson.locator('.hr-content').waitFor({state:'visible'});
    assert.equal(await page.getByRole('searchbox').inputValue(),'');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    await page.screenshot({path:path.join(root,'output/dev30-history-mobile.png')});
    await page.goto(`${base}/history/?event=${id}#history-event-${id}-sources`);
    await lesson.locator('h3[id$=sources]').waitFor({state:'visible'});
    await page.waitForFunction(id=>{const r=document.getElementById(`history-event-${id}-sources`)?.getBoundingClientRect();return r&&r.top>=0&&r.top<innerHeight},id);
    assert.equal(new URL(page.url()).hash,`#history-event-${id}-sources`);
    const group=dataset.events.find(event=>event.isGroup && event.publication!=='pending');
    await page.goto(`${base}/history/?event=${encodeURIComponent(group.id)}`);
    await page.locator(`#history-event-${group.id} .hr-content`).waitFor({state:'visible'});
    assert.equal(await page.getByRole('checkbox',{name:'查看原表合并组'}).isChecked(),true);
    await page.goto(`${base}/history/?event=does-not-exist`);
    await page.getByText('链接中的事件不存在或尚未发布',{exact:true}).waitFor();
    await context.clearCookies();
    await page.goto(`${base}/history/?event=${id}`, {waitUntil:'domcontentloaded'});
    await page.waitForURL(/\/login\?/);
    assert.equal(new URL(page.url()).searchParams.get('redirect'),`/history/?event=${id}`);
    assert.deepEqual(errors,[]);
    console.log('PASS: real production UI / fixture API: deep link and archive group, refresh, back, manual citation, missing link, mobile overflow, login return URL; no page errors');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

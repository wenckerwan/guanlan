// Production UI with fixture API; does not verify live authentication or deployment.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const content=JSON.parse(fs.readFileSync(path.join(root,'vendor/mayuan/apps/api/resources/content.json'),'utf8'));
const base=process.argv[2]||'http://127.0.0.1:4189';
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true,args:['--enable-unsafe-swiftshader']});
 try {
  const page=await browser.newPage({viewport:{width:390,height:844}});page.setDefaultTimeout(15000);
  await page.route('**/visit-tracker.js',route=>route.fulfill({contentType:'application/javascript',body:'/* main-site tracker omitted in isolated Mayuan preview */'}));
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/api/v2/**',route=>route.fulfill({json:{data:route.request().url().endsWith('/session')?{mode:'standalone'}:content}}));
  await page.addInitScript(()=>Object.defineProperty(navigator,'clipboard',{value:{writeText:async()=>{throw Error('denied')}}}));
  await page.goto(`${base}/mayuan/?concept=practice#concept-practice-sources`);
  const heading=page.locator('#concept-practice-sources');await heading.waitFor();
  await page.waitForFunction(()=>{const r=document.getElementById('concept-practice-sources')?.getBoundingClientRect();return r&&r.top>=0&&r.top<innerHeight});
  await page.reload();await heading.waitFor();
  await page.waitForFunction(()=>{const r=document.getElementById('concept-practice-sources')?.getBoundingClientRect();return r&&r.top>=0&&r.top<innerHeight});
  await page.getByRole('button',{name:'复制引用',exact:true}).click();
  const fallback=page.locator('.drawer textarea[readonly]');await fallback.waitFor();
  assert.match(await fallback.inputValue(),/practice/);
  await page.getByRole('button',{name:'关闭概念详情'}).click();
  await page.waitForFunction(()=>!new URL(location.href).searchParams.has('concept'));
  await page.goBack();await heading.waitFor();
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await page.screenshot({path:path.join(root,'output/dev30-mayuan-mobile.png')});
  await page.goto(`${base}/mayuan/?concept=missing-dev30`);
  await page.getByRole('status').filter({hasText:'此链接对应的概念不存在或已移除'}).waitFor();
  assert.deepEqual(errors,[]);
  console.log('PASS: production Mayuan UI / fixture API: concept and source fragment refresh, back, close, clipboard fallback, missing ID, mobile overflow; no page errors');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});

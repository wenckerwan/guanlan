// Production preview with isolated API fixtures; no live model, mail or database calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const dataset = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../storage/history/history.v1.json'), 'utf8'));
const base = process.argv[2] || 'http://localhost:3039';
const until = async (fn) => { for (let n=0;n<100;n++) { if(await fn()) return; await new Promise(r=>setTimeout(r,100)); } throw Error('fixture assertion timed out'); };
(async () => {
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined, headless: true });
  try {
    const context = await browser.newContext();
    await context.addCookies([{ name: 'guanlan.token', value: 'fixture-A', url: base }]);
    await context.addInitScript(() => localStorage.setItem('ai_analysis_config', JSON.stringify({ provider: 'custom', endpoint: 'https://fixture.invalid/analysis' })));
    let mode='complete', fallback=0, saves=0, offline=false, expired=false, notes=[], favorites=[], reports=[];
    const bNotes=[], bFavorites=[], bWrites=[];
    const errors=[];
    await context.route('**/api/**', async route => {
      const req=route.request(), url=new URL(req.url()), method=req.method(), body=req.postDataJSON();
      const reply=data=>route.fulfill({json:{data}});
      const isB=req.headers().authorization==='Bearer fixture-B';
      if(url.pathname.endsWith('/auth/me')) return reply({user:{id:isB?2:1,name:isB?'Fixture B':'Fixture A',role:'student',code:'fixture'}});
      if(url.pathname.endsWith('/history/events')) return reply(dataset);
      if(url.pathname.includes('/study/')) {
        if(isB) {
          if(method!=='GET') bWrites.push(body);
          if(url.pathname.includes('/notes')) {
            if(method==='POST') bNotes.push({id:bNotes.length+1,...body});
            return reply(bNotes);
          }
          if(url.pathname.includes('/favorites')) return reply(bFavorites);
          return reply([]);
        }
        if(offline) return route.abort('internetdisconnected');
        if(expired) return route.fulfill({status:401,json:{message:'expired'}});
        if(url.pathname.includes('/notes')) {
          if(method==='POST') notes.push({id:notes.length+1,...body});
          if(method==='PATCH') Object.assign(notes.find(n=>String(n.id)===url.pathname.split('/').at(-1)),body);
          if(method==='DELETE') notes=notes.filter(n=>String(n.id)!==url.pathname.split('/').at(-1));
          return reply(notes);
        }
        if(url.pathname.includes('/favorites')) { if(method==='PUT') {favorites=favorites.filter(n=>n.targetId!==body.targetId);if(body.favorited)favorites.push({id:1,...body});} return reply(favorites); }
        return reply([]);
      }
      if(url.pathname.endsWith('/ai-analysis-stream')) {
        if(mode==='unsupported') return route.fulfill({status:404,json:{message:'unsupported'}});
        if(mode==='denied') return route.fulfill({status:401,json:{message:'expired'}});
        return route.fulfill({contentType:'text/event-stream',body:'event: delta\r\ndata: '+JSON.stringify({delta:'# 完整报告\n<img src=x onerror="window.__xss=1">'})+'\r\n\r\n'+(mode==='partial'?'':'event: done\r\ndata: {"done":true}\r\n\r\n')});
      }
      if(url.pathname.endsWith('/ai-analysis')) {fallback++;return reply({content:'# 回退报告'});}
      if(url.pathname.endsWith('/analysis-reports')) {
        if(method==='POST') {saves++;reports.push({id:saves,title:'历史报告 '+saves,createdAt:'2026-10-22T00:00:00',markdown:body.markdown});}
        return reply(reports);
      }
      if(url.pathname.includes('/analysis-reports/')) return reply({title:'历史报告',markdown:'# 历史安全报告\n<script>window.__xss=1</script>'});
      if(url.pathname.endsWith('/students/fixture')) return reply({code:'fixture',name:'Fixture A',relation:'本人',itemCount:1});
      return reply([]);
    });
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    await page.goto(base+'/mistakes/analyze/fixture');
    await page.locator('input[type=file]').setInputFiles({name:'fixture.md',mimeType:'text/markdown',buffer:Buffer.from('# 错题\n测试题')});
    await page.locator('input[type=checkbox]').uncheck();
    const run=async()=>{await page.getByRole('button',{name:'开始 AI 分析',exact:true}).click();await until(async()=>!(await page.getByRole('button',{name:'开始 AI 分析',exact:true}).isDisabled()));};
    await run();assert.equal(saves,1);assert.equal(fallback,0);
    assert.equal(await page.evaluate(()=>window.__xss),undefined);
    assert.equal(await page.locator('.markdown-content [onerror]').count(),0);
    await page.getByRole('button',{name:'历史报告 1',exact:true}).click();
    await until(async()=>(await page.locator('.markdown-content').innerText()).includes('历史安全报告'));
    assert.equal(await page.locator('.markdown-content script').count(),0);
    mode='partial';await run();assert.equal(saves,1);assert.equal(fallback,0);assert.ok((await page.locator('body').innerText()).includes('未自动保存'));
    mode='unsupported';await run();assert.equal(saves,2);assert.equal(fallback,1);
    mode='denied';await run();assert.equal(saves,2);assert.equal(fallback,1);
    console.log('PASS AI complete/partial/unsupported/401/history/sanitization');
    await context.addCookies([{ name: 'guanlan.token', value: 'fixture-A', url: base }]);
    await page.goto(base+'/history');await page.waitForSelector('.hr-reader').catch(async e=>{console.error(await page.locator('body').innerText());throw e;});
    await page.getByRole('searchbox').fill('虎门');await page.locator('.hr-table-scroll a').first().click();
    const note=page.locator('.hr-note textarea');await note.fill('在线原始笔记');await note.blur();
    await until(()=>notes.some(n=>n.content==='在线原始笔记'));
    offline=true;await context.setOffline(true);
    await until(async()=> (await page.locator('.hr-note small').innerText()).includes('待同步'));
    await note.fill('离线修改笔记');await note.blur();
    await until(async()=>(await page.locator('.hr-note small').innerText()).includes('待同步'));
    offline=false;await context.setOffline(false);
    await until(()=>notes.some(n=>n.content==='离线修改笔记'));
    await until(async()=> (await page.locator('.hr-note small').innerText()).includes('已同步到账号'));
    await until(async()=>!(await note.isDisabled()));assert.equal(await note.inputValue(),'离线修改笔记');
    expired=true;await note.fill('登录失效后笔记');await note.blur();
    await page.waitForURL('**/login?**');
    assert.equal(notes[0].content,'离线修改笔记');
    expired=false;await context.addCookies([{name:'guanlan.token',value:'fixture-A',url:base}]);await page.goto(base+'/history');await page.waitForSelector('.hr-reader');
    await until(()=>notes.some(n=>n.content==='登录失效后笔记'));
    // Change the real cookie in the same browser context while A has a pending draft.
    await page.getByRole('searchbox').fill('虎门');await page.locator('.hr-table-scroll a').first().click();
    offline=true;await note.fill('A账号待同步私有草稿');await note.blur();
    await until(async()=>(await page.locator('.hr-note small').innerText()).includes('待同步'));
    await context.addCookies([{name:'guanlan.token',value:'fixture-B',url:base}]);
    await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
    await until(async()=>await note.isVisible() && (await note.inputValue())==='');
    assert.equal(bNotes.length,0);
    assert.ok(bWrites.every(body=>!JSON.stringify(body).includes('A账号待同步私有草稿')));
    offline=false;
    await context.addCookies([{name:'guanlan.token',value:'fixture-A',url:base}]);
    await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
    await until(()=>notes.some(n=>n.content==='A账号待同步私有草稿'));
    assert.deepEqual(errors,[]);
    console.log('PASS history offline edit/reconnect/401/pending replay/account switch; no page errors');
    await context.close();
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

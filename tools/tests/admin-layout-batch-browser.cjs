const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
 const browser = await chromium.launch({channel:'msedge',headless:true});
 try {
  const context=await browser.newContext();const base=process.env.ADMIN_BROWSER_BASE||'http://127.0.0.1:3209';
  const cached={id:1,email:'admin@example.test',displayName:'Admin',role:'admin',status:'active',userGroup:'user'};
  await context.addCookies([{name:'guanlan.token',value:'test-token',url:base}]);
  await context.addInitScript(user=>localStorage.setItem('guanlan.user',JSON.stringify(user)),cached);
  let role='admin',meStatus=200,adminReads=0,batches=0,holdNextMe=false,meHeld=false,releaseMe;
  const holdMePromise=new Promise(resolve=>{releaseMe=resolve});
  await context.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(req.isNavigationRequest()){const headers={...req.headers()};delete headers.cookie;await route.continue({headers});return;}
   if(!url.pathname.startsWith('/api/v1/')){await route.continue();return;}
   let data=[];
   if(url.pathname.endsWith('/auth/me')){const capturedStatus=meStatus,capturedRole=role;if(holdNextMe){holdNextMe=false;meHeld=true;await holdMePromise;}await route.fulfill({status:capturedStatus,json:capturedStatus===200?{data:{user:{...cached,role:capturedRole}}}:{message:'test auth error'}});return;}
   if(url.pathname.includes('/admin/'))adminReads++;
   if(url.pathname.endsWith('/admin/users'))data={items:[],total:0,page:1,perPage:20};
   if(url.pathname.endsWith('/admin/hotspots'))data={items:[{id:1,title:'批量甲',slug:'a',status:'published',revision:1},{id:2,title:'批量乙',slug:'b',status:'published',revision:1}],total:2,page:1,perPage:20,filters:{periods:[],categories:[]}};
   if(url.pathname.endsWith('/status-batch')){batches++;data={results:[{id:1,ok:true,status:200,revision:2},{id:2,ok:false,status:409,message:'文章已被修改'}],succeeded:1,failed:1};}
   await route.fulfill({json:{data}});
  });
  const page=await context.newPage();page.setDefaultTimeout(6000);page.setDefaultNavigationTimeout(30000);const errors=[];page.on('pageerror',e=>errors.push(e.stack || e.message));page.on('console',m=>{if(m.type()==='warning'&&m.text().includes('Hydration'))console.log('HYDRATION '+m.text().slice(0,300));});
  await page.goto(`${base}/admin/users`,{waitUntil:'domcontentloaded'});
  await page.getByRole('navigation',{name:'管理后台导航',exact:true}).waitFor();
  assert.equal(await page.getByRole('navigation',{name:'管理后台导航',exact:true}).getByRole('link').count(),10);
  console.log('PASS direct admin page has shared navigation');
  role='user';adminReads=0;await page.reload();
  await page.getByRole('heading',{name:'没有后台权限',exact:true}).waitFor();assert.equal(adminReads,0,'cached administrator mounted private page');
  console.log('PASS fresh role overrides cached admin without private requests');
  meStatus=500;await page.reload();await page.getByText('后台身份验证暂时失败，请重试。',{exact:true}).waitFor();
  meStatus=200;role='admin';await page.getByRole('button',{name:'重试身份验证',exact:true}).click();
  await page.getByRole('navigation',{name:'管理后台导航',exact:true}).waitFor();
  console.log('PASS identity outage retains recoverable session');
  holdNextMe=true;meStatus=401;await page.reload();
  const deadline=Date.now()+5000;while(!meHeld&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,10));assert.ok(meHeld,'identity request not held');
  meStatus=200;
  await page.evaluate(()=>{const nuxt=document.getElementById('__nuxt').__vue_app__.config.globalProperties.$nuxt;const key='$sauth.token';if(!(key in nuxt.payload.state))throw new Error('Token state unavailable');document.cookie='guanlan.token=replacement-token; Path=/';nuxt.payload.state[key]='replacement-token';});
  releaseMe();
  await page.getByRole('navigation',{name:'管理后台导航',exact:true}).waitFor();
  assert.equal(await page.evaluate(()=>document.cookie.includes('replacement-token')),true,'stale identity cleared replacement session');
  console.log('PASS obsolete identity rejection cannot invalidate replacement session');
  await page.goto(`${base}/admin/hotspots`,{waitUntil:'domcontentloaded'});
  await page.getByText('批量甲',{exact:true}).first().waitFor();
  await page.getByRole('button',{name:'批量状态管理',exact:true}).click();
  await page.getByLabel('选择本页全部文章',{exact:true}).check();
  page.once('dialog',d=>d.dismiss());await page.getByRole('button',{name:'批量隐藏',exact:true}).click();assert.equal(batches,0);
  page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'批量隐藏',exact:true}).click();
  await page.getByText('成功 1 篇，失败 1 篇',{exact:true}).waitFor();assert.equal(batches,1);
  await page.getByText(/文章已被修改/).waitFor();
  console.log('PASS status batch cancellation and explicit partial conflict');
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'mobile page overflow');
  assert.equal(errors.length,0,errors.join('\n'));
  console.log('PASS mobile containment and no page errors');
  meStatus=401;await page.reload();await page.getByRole('heading',{name:'需要登录',exact:true}).waitFor();
  const href=await page.getByRole('link',{name:'前往登录',exact:true}).getAttribute('href');assert.ok(href.includes('redirect='));
  console.log('PASS expired identity prompts login with return path');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

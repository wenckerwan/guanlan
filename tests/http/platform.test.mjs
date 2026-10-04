import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdtempSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {root,phpExecutable} from '../../scripts/runtime.mjs';

test('real HTTP: authentication, grading, replay, isolation, import and admin lifecycle',async t=>{
 const folder=mkdtempSync(path.join(tmpdir(),'mayuan-http-'));
 const origin='http://127.0.0.1:4179',base='http://127.0.0.1:8091/api/v2';
 const start=()=>{const p=spawn(phpExecutable(),['-S','127.0.0.1:8091','-t',path.join(root,'apps/api/public'),path.join(root,'apps/api/public/router.php')],{cwd:root,env:{...process.env,MAYUAN_MODE:'development',MAYUAN_DB_DSN:'sqlite:'+path.join(folder,'test.sqlite'),MAYUAN_ORIGINS:origin},stdio:'ignore'});p.on('error',()=>{});return p;};
 let server=start();
 t.after(async()=>{server.kill();await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));if(!path.resolve(folder).startsWith(path.resolve(tmpdir())+path.sep))throw new Error('Refusing cleanup outside temporary directory');rmSync(folder,{recursive:true,force:true});});
 let ready=false;
 for(let i=0;i<80;i++){try{const r=await fetch(base+'/session');const body=await r.json();if(r.ok&&body.data?.mode==='development'){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,50));}
 assert.ok(ready,'isolated PHP server starts');
 const client=(jar={cookie:''})=>{
  let principal='';
  return async(url,method='GET',body)=>{
   const r=await fetch(base+url,{method,headers:{...(jar.cookie?{Cookie:jar.cookie}:{}),...(principal?{'X-Mayuan-User':principal}:{}),...(body!==undefined?{'Content-Type':'application/json',Origin:origin}:{})},body:body===undefined?undefined:JSON.stringify(body)});
   const set=r.headers.getSetCookie();if(set.length)jar.cookie=set.map(v=>v.split(';')[0]).join('; ');
   const json=await r.json();if(['/session','/dev-login'].includes(url)&&r.ok)principal=json.data.user?.id||'';if(url==='/logout'&&r.ok)principal='';return {status:r.status,data:json.data,error:json.error};
  };
 };
 const a=client(),b=client(),admin=client();
 const seed=JSON.parse(readFileSync(path.join(root,'content/seed.json'),'utf8'));
 let state;
 await t.test('public session and content do not grant study access or expose quiz answers',async()=>{
  assert.equal((await a('/session')).data.user,null);
  assert.equal((await a('/state')).status,401);
  const content=(await a('/content')).data;
  assert.equal(content.nodes.length,101);
  assert.equal(content.exercises.length,30);
  assert.ok(content.exercises.every(q=>!Object.hasOwn(q,'answer')&&!Object.hasOwn(q,'explanation')));
 });
 await t.test('mutation from an unrelated browser origin is rejected',async()=>{
  const r=await fetch(base+'/dev-login',{method:'POST',headers:{'Content-Type':'application/json',Origin:'https://untrusted.example'},body:JSON.stringify({account:'admin'})});assert.equal(r.status,403);
 });
 await t.test('development login and immutable replay persist exactly one assessment',async()=>{
  assert.equal((await a('/dev-login','POST',{account:'learner'})).status,200);
  state=(await a('/state')).data;
  const event={id:'http-mastery',type:'mastery',payload:{nodeId:'practice',mastery:'mastered'},baseRevision:state.revision};
  const r=await a('/events','POST',event);assert.equal(r.status,200);state=r.data;
  assert.equal(state.nodes.practice.mastery,'mastered');
  const replay=await a('/events','POST',event);assert.equal(replay.status,200);assert.equal(replay.data.revision,state.revision);
  const changed=await a('/events','POST',{...event,payload:{nodeId:'practice',mastery:'fuzzy'}});assert.equal(changed.status,409);
 });
 await t.test('server grading ignores forged correct answer and deduplicates attempts',async()=>{
  const q=seed.exercises[0];const body={eventId:'http-answer',exerciseId:q.id,chosen:q.answer,reason:'',correct:false};
  const r=await a('/attempts','POST',body);assert.equal(r.status,200);assert.equal(r.data.correct,true);state=r.data.state;
  const again=await a('/attempts','POST',body);assert.equal(again.status,200);assert.equal(again.data.state.summary.attempts,state.summary.attempts);
 });
 await t.test('stale self-assessment conflicts rather than overwriting',async()=>{
  const r=await a('/events','POST',{id:'http-stale',type:'mastery',baseRevision:0,payload:{nodeId:'practice',mastery:'fuzzy'}});assert.equal(r.status,409);
  assert.equal((await a('/state')).data.nodes.practice.mastery,'mastered');
 });
 await t.test('separate account does not inherit learner data or administration',async()=>{
  assert.equal((await b('/dev-login','POST',{account:'second'})).status,200);
  const s=(await b('/state')).data;assert.equal(s.summary.attempts,0);assert.equal(s.summary.mastered,0);
  assert.equal((await b('/admin/content')).status,403);
 });
 await t.test('shared browser cookie change cannot submit or read another tab account data',async()=>{
  const jar={cookie:''},tabA=client(jar),tabB=client(jar);
  await tabA('/dev-login','POST',{account:'learner'});
  await tabB('/dev-login','POST',{account:'second'});
  const before=(await tabB('/state')).data;
  const stale=await tabA('/events','POST',{id:'cross-tab-owner',type:'visit',payload:{nodeId:'truth'}});
  assert.equal(stale.status,409);assert.equal(stale.error.code,'session_changed');
  assert.equal((await tabA('/state')).status,409);
  assert.equal((await tabB('/state')).data.revision,before.revision);
 });
 await t.test('malformed import changes no state; backup restores through explicit confirmation',async()=>{
  const before=(await a('/state')).data.revision;
  assert.equal((await a('/import','POST',{progress:{version:1,nodes:{unknown:{}}},confirm:true})).status,422);
  assert.equal((await a('/state')).data.revision,before);
  const backup=(await a('/export')).data;
  assert.equal((await a('/import','POST',{progress:backup,confirm:false})).status,422);
  assert.equal((await a('/import','POST',{progress:backup,confirm:true})).status,200);
 });
 await t.test('draft remains private until publish and rollback restores prior content',async()=>{
  assert.equal((await admin('/dev-login','POST',{account:'admin'})).status,200);
  const original=(await admin('/admin/content')).data;
  const changed=structuredClone(original.draft);changed.nodes[0].summary+='（HTTP验收草稿）';
  const saved=await admin('/admin/content','PUT',{content:changed,baseRevision:original.revision});assert.equal(saved.status,200);
  assert.equal((await a('/content')).data.nodes[0].summary,original.published.nodes[0].summary);
  const published=await admin('/admin/publish','POST',{revision:saved.data.revision});assert.equal(published.status,200);
  assert.equal((await a('/content')).data.nodes[0].summary,changed.nodes[0].summary);
  const rolled=await admin('/admin/rollback','POST',{version:original.published.contentVersion});assert.equal(rolled.status,200);
  assert.equal((await a('/content')).data.nodes[0].summary,original.published.nodes[0].summary);
 });
 await t.test('database and authenticated record survive PHP process restart',async()=>{
  const before=(await a('/state')).data;
  await new Promise(resolve=>{server.once('exit',resolve);server.kill();});server=start();
  let restarted=false;for(let i=0;i<80;i++){try{const r=await fetch(base+'/session');if(r.ok&&(await r.json()).data?.mode==='development'){restarted=true;break;}}catch{}await new Promise(r=>setTimeout(r,50));}
  assert.ok(restarted);const after=(await a('/state')).data;
  assert.equal(after.revision,before.revision);assert.equal(after.nodes.practice.mastery,'mastered');assert.equal(after.summary.attempts,before.summary.attempts);
 });
 await t.test('logout revokes current HTTP session',async()=>{
  assert.equal((await a('/logout','POST',{})).status,200);
  assert.equal((await a('/state')).status,401);
 });
});

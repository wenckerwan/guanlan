import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { build } from 'esbuild'
const require = createRequire(import.meta.url)
async function component() {
 const source = (await readFile(new URL('../components/history/ui/HistoryExplorer.vue', import.meta.url), 'utf8')).split('<script setup lang="ts">')[1].split('</script>')[0]
 const result = await build({stdin:{contents:source+'\nexport { readHash, dataset, selectedId, query, topicIds, archive, windowRange, nav, ready, compareIds };',resolveDir:fileURLToPath(new URL('../components/history/ui/',import.meta.url)),loader:'ts'},bundle:true,write:false,platform:'node',format:'cjs',external:['vue'],loader:{'.css':'empty','.vue':'empty'}})
 let parsed={nav:null,view:{},selectedId:'target'}
 const box={module:{exports:{}},exports:{},require:n=>n==='vue'?{...require('vue'),onMounted(){},onBeforeUnmount(){},watch(){}}:require(n),defineProps:()=>({repository:{},studyRepository:{},router:{parse:()=>parsed}}),navigator:{onLine:true},setTimeout:()=>0,clearTimeout(){}}
 vm.runInNewContext(result.outputFiles[0].text,box)
 return {ui:box.module.exports,setRoute:value=>parsed=value}
}
test('actual history component reveals deep link despite saved filters and clears selection on back',async()=>{
 const {ui,setRoute}=await component()
 ui.dataset.value={range:{start:1839,end:2027},events:[{id:'target',publication:'verified'}]}
 ui.query.value='hidden';ui.topicIds.value=['other'];ui.archive.value=true;ui.windowRange.value={start:2000,end:2027};ui.nav.value='compare'
 ui.readHash()
 assert.equal(ui.selectedId.value,'target');assert.equal(ui.query.value,'');assert.equal(ui.topicIds.value.length,0);assert.equal(ui.archive.value,false);assert.equal(ui.windowRange.value.start,1839);assert.equal(ui.nav.value,'explore')
 setRoute({nav:null,view:{},selectedId:null});ui.readHash();assert.equal(ui.selectedId.value,null)
 setRoute({nav:null,view:{},selectedId:'missing'});ui.readHash();assert.equal(ui.selectedId.value,null)
})
test('Guanlan router subscribes to host navigation and disposes listener',async()=>{
 const result=await build({entryPoints:[fileURLToPath(new URL('../components/history/ui/router.ts',import.meta.url))],bundle:true,write:false,platform:'node',format:'cjs'})
 const box={module:{exports:{}},exports:{}};vm.runInNewContext(result.outputFiles[0].text,box)
 let callback,disposed=false,calls=0
 const route={query:{event:'target'},push(){},replace(){},listen(fn){callback=fn;return()=>disposed=true}}
 const router=box.module.exports.createGuanlanRouter({route});const stop=router.listen(()=>calls++)
 assert.equal(typeof callback,'function');callback();assert.equal(calls,1);stop();assert.equal(disposed,true)
})

test('history deep links reveal groups, reject pending, and accept original publication',async()=>{
 const {ui,setRoute}=await component()
 ui.dataset.value={range:{start:1839,end:2027},events:[
  {id:'group',publication:'verified',isGroup:true},
  {id:'pending',publication:'pending',isGroup:true},
  {id:'original',publication:'original',isGroup:false},
 ]}
 for (const [id,selected,archive] of [['group','group',true],['pending',null,true],['original','original',false]]) {
  setRoute({nav:null,view:{},selectedId:id});ui.readHash()
  assert.equal(ui.selectedId.value,selected)
  assert.equal(ui.archive.value,archive)
 }
})

test('serialized explore navigation reveals selection hidden by restored filters',async()=>{
 const {ui,setRoute}=await component()
 const target={id:'tiger',publication:'original',isGroup:false,title:'虎门销烟',content:'',note:'',tags:[],topicId:'early',dates:{original:{label:'1839',start:{year:1839},end:{year:1839}}}}
 ui.dataset.value={range:{start:1839,end:2027},events:[target]}
 setRoute({nav:'explore',view:{query:'遵义会议',topicIds:['other'],archive:true,start:2000,end:2027},selectedId:'tiger'})
 ui.readHash()
 assert.equal(ui.selectedId.value,'tiger');assert.equal(ui.query.value,'');assert.equal(ui.topicIds.value.length,0);assert.equal(ui.archive.value,false);assert.equal(ui.windowRange.value.start,1839)
})


test('self-written explore route preserves newly typed search while back still reveals target',async()=>{
 const {ui,setRoute}=await component()
 const tiger={id:'tiger',publication:'original',isGroup:false,title:'虎门销烟',content:'',note:'',tags:[],dates:{}}
 ui.dataset.value={range:{start:1839,end:2027},events:[tiger]}
 ui.ready.value=true;ui.selectedId.value='tiger';ui.query.value='遵义会议';ui.nav.value='explore'
 const view={query:'遵义会议',topicIds:[],compareIds:[],archive:false,start:1839,end:2027}
 setRoute({nav:'explore',view,selectedId:'tiger'});ui.readHash()
 assert.equal(ui.query.value,'遵义会议')
 ui.selectedId.value='zunyi'
 setRoute({nav:'explore',view,selectedId:'tiger'});ui.readHash()
 assert.equal(ui.selectedId.value,'tiger');assert.equal(ui.query.value,'')
})

test('host route adapter serializes push before duplicate and changed replace writes',async()=>{
 const source=await readFile(new URL('../pages/history/index.vue',import.meta.url),'utf8')
 const adapter=source.slice(source.indexOf('// Nuxt 路由薄封装'),source.indexOf('async function mountHistory'))
 const result=await build({stdin:{contents:adapter+'\nexport {routeAdapter}',loader:'ts'},write:false,platform:'node',format:'cjs'})
 const calls=[];let finishPush
 const route={path:'/history/',fullPath:'/history/',hash:'#history-event-zunyi-content',query:{}}
 const box={module:{exports:{}},exports:{},route,disposed:false,nextTick:async()=>{},watch(){},navigateTo:(target,options)=>{
  calls.push({target,options})
  if(!options.replace)return new Promise(resolve=>{finishPush=()=>{route.query=target.query;resolve()}})
  route.query=target.query;return Promise.resolve()
 }}
 vm.runInNewContext(result.outputFiles[0].text,box)
 const a=box.module.exports.routeAdapter
 a.push({event:'zunyi'});a.replace({event:'zunyi'});a.replace({event:'zunyi',q:'test'})
 await new Promise(resolve=>setImmediate(resolve))
 assert.equal(calls.length,1,'replace cannot cancel in-flight push')
 finishPush();await new Promise(resolve=>setImmediate(resolve))
 assert.equal(calls.length,2,'duplicate replace is skipped')
 assert.equal(calls[0].options.replace,false);assert.equal(calls[1].options.replace,true)
 assert.equal(calls[1].target.query.q,'test');assert.equal(calls[0].target.hash,'#history-event-zunyi-content');assert.equal(calls[1].target.hash,'#history-event-zunyi-content')
})

test('host route queue recovers after rejected navigation without unhandled failure',async()=>{
 const source=await readFile(new URL('../pages/history/index.vue',import.meta.url),'utf8')
 const adapter=source.slice(source.indexOf('// Nuxt 路由薄封装'),source.indexOf('async function mountHistory'))
 const result=await build({stdin:{contents:adapter+'\nexport {routeAdapter}',loader:'ts'},write:false,platform:'node',format:'cjs'})
 const calls=[],warnings=[];const route={path:'/history/',query:{}}
 const box={module:{exports:{}},exports:{},route,disposed:false,nextTick:async()=>{},watch(){},console:{warn:(...args)=>warnings.push(args)},navigateTo:async target=>{calls.push(target);if(calls.length===1)throw Error('navigation failed');route.query=target.query}}
 vm.runInNewContext(result.outputFiles[0].text,box)
 box.module.exports.routeAdapter.push({event:'first'})
 box.module.exports.routeAdapter.replace({event:'second'})
 await new Promise(resolve=>setImmediate(resolve))
 assert.equal(warnings.length,1);assert.equal(calls.length,2);assert.equal(route.query.event,'second')
})

test('delayed own route echoes never deliver stale typing but external Back is delivered',async()=>{
 const source=await readFile(new URL('../pages/history/index.vue',import.meta.url),'utf8')
 const adapter=source.slice(source.indexOf('// Nuxt 路由薄封装'),source.indexOf('async function mountHistory'))
 const result=await build({stdin:{contents:adapter+'\nexport {routeAdapter}',loader:'ts'},write:false,platform:'node',format:'cjs'})
 let listener,finish;const delivered=[],targets=[]
 const route={path:'/history/',hash:'#chapter',query:{event:'tiger'}}
 const box={module:{exports:{}},exports:{},route,disposed:false,nextTick:async()=>{},watch:(_getter,callback)=>{listener=callback;return()=>{}},navigateTo:target=>new Promise(resolve=>{
  targets.push(target)
  finish=()=>{route.query=target.query;route.hash=target.hash;listener();resolve()}
 })}
 vm.runInNewContext(result.outputFiles[0].text,box)
 const a=box.module.exports.routeAdapter
 a.listen(()=>delivered.push(route.query.q || route.query.event))
 a.replace({event:'tiger',q:'遵'});a.replace({event:'tiger',q:'遵义'})
 await new Promise(resolve=>setImmediate(resolve))
 finish();await new Promise(resolve=>setImmediate(resolve))
 assert.equal(targets.length,2);assert.deepEqual(delivered,[])
 finish();await new Promise(resolve=>setImmediate(resolve))
 assert.deepEqual(delivered,[]);assert.equal(route.query.q,'遵义')
 route.query={event:'external'};listener()
 assert.deepEqual(delivered,['external'])
})

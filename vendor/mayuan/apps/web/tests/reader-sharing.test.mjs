import test from 'node:test'
import assert from 'node:assert/strict'
import { resolveConcept, conceptCitation, copyText, readerAnchor } from '../app/deep-link.mjs'
test('resolves known, absent and missing IDs without selecting a substitute', () => {
 const nodes = [{id:'practice',title:'实践'}]
 assert.deepEqual(resolveConcept('/mayuan/?concept=practice', nodes), {node:nodes[0],missing:false})
 assert.deepEqual(resolveConcept('/mayuan/?concept=gone', nodes), {node:null,missing:true})
 assert.deepEqual(resolveConcept('/mayuan/', nodes), {node:null,missing:false})
})
test('citation uses only supplied provenance and stable URL', () => {
 const text = conceptCitation({id:'practice',title:'实践',sources:[{label:'笔记',locator:'第一节'}]}, {contentVersion:'v3'}, 'https://example.com/mayuan/?concept=practice')
 assert.match(text,/笔记：第一节/)
 assert.match(text,/v3/)
 assert.doesNotMatch(text,/undefined|访问时间|教材/)
 assert.equal(readerAnchor('practice','sources'),'concept-practice-sources')
})
test('clipboard denial and unavailable API return manual copy text', async () => {
 assert.deepEqual(await copyText('link',undefined), {copied:false,text:'link'})
 assert.deepEqual(await copyText('cite',{writeText:async()=>{throw Error('denied')}}), {copied:false,text:'cite'})
 let written
 assert.deepEqual(await copyText('ok',{writeText:async text=>{written=text}}), {copied:true,text:'ok'})
 assert.equal(written,'ok')
})

test('reader hash restoration only permits own known section anchors', async () => {
 const { readerSectionFromUrl } = await import('../app/deep-link.mjs')
 assert.equal(readerSectionFromUrl('/mayuan/?concept=practice#concept-practice-sources','practice'),'concept-practice-sources')
 assert.equal(readerSectionFromUrl('/mayuan/?concept=practice#concept-other-sources','practice'),null)
 assert.equal(readerSectionFromUrl('/mayuan/?concept=practice#concept-practice-unknown','practice'),null)
 assert.equal(readerSectionFromUrl('/mayuan/?concept=practice#%E0%A4%A','practice'),null)
})

test('actual restoreConcept waits for reader rendering and restores same-concept popstate sections', async () => {
 const { readFile } = await import('node:fs/promises')
 const vm = await import('node:vm')
 const { readerSectionFromUrl } = await import('../app/deep-link.mjs')
 const source = await readFile(new URL('../app/app.vue', import.meta.url), 'utf8')
 const restore = source.slice(source.indexOf('async function restoreConcept()'), source.indexOf('watch(() => selected.value?.id'))
 const order = []
 const context = { content:{value:{}}, nodes:{value:[{id:'practice'}]}, selected:{value:null}, relation:{value:null}, manualCopy:{value:''}, notice:{value:''}, view:{value:''}, query:{value:''}, chapter:{value:''}, resolvingUrl:false,
  resolveConcept, readerSectionFromUrl, event(){}, nextTick:async()=>{order.push('render')}, window:{location:{href:'/mayuan/?concept=practice#concept-practice-sources'}}, document:{getElementById:id=>({scrollIntoView:()=>order.push(id)})} }
 vm.runInNewContext(restore,context)
 await context.restoreConcept()
 assert.deepEqual(order,['render','render','concept-practice-sources'])
 order.length=0
 context.window.location.href='/mayuan/?concept=practice#concept-practice-usage'
 await context.restoreConcept()
 assert.deepEqual(order,['render','render','concept-practice-usage'])
 order.length=0
 context.window.location.href='/mayuan/?concept=practice#concept-other-sources'
 await context.restoreConcept()
 assert.deepEqual(order,['render','render'])
})

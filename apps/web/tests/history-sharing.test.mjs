import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { build } from 'esbuild'

const result = await build({entryPoints:['components/history/ui/share.ts'],bundle:true,write:false,platform:'node',format:'cjs'})
const box={module:{exports:{}},exports:{}}
vm.runInNewContext(result.outputFiles[0].text,box)
const {historyCitation,copyReaderText}=box.module.exports

test('citation includes recorded provenance and stable URL, never personal notes',()=>{
  const event={title:'遵义会议',dates:{textbook:{label:'1935年1月',sourceId:'book',page:42},original:null},note:'editor note'}
  const dataset={version:'v1',sources:[{id:'book',title:'教材'}],notes:{private:'secret'}}
  const text=historyCitation(event,dataset,'https://example.test/history/?event=stable')
  assert.match(text,/教材时间：1935年1月；教材，PDF 第 42 页/)
  assert.match(text,/数据版本：v1/)
  assert.match(text,/event=stable$/)
  assert.doesNotMatch(text,/secret|editor note/)
  event.dates.textbook.sourceId='absent';event.dates.textbook.page=null
  assert.match(historyCitation(event,dataset,'url'),/来源未登记，页码未定位/)
})

test('clipboard success and rejection produce reliable manual-copy state',async()=>{
  let written
  assert.equal(await copyReaderText('exact text',{writeText:async text=>written=text}),true)
  assert.equal(written,'exact text')
  assert.equal(await copyReaderText('text'),false)
  assert.equal(await copyReaderText('text',{writeText:async()=>{throw Error('denied')}}),false)
})

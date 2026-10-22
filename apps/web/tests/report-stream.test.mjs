import test from 'node:test'
import assert from 'node:assert/strict'
import { consumeReportStream, ReportStreamError } from '../utils/report-stream.mjs'
const response = chunks => new Response(new ReadableStream({ start(c) { for (const s of chunks) c.enqueue(new TextEncoder().encode(s)); c.close() } }), { headers: { 'Content-Type': 'text/event-stream' } })
test('stream handles split UTF8 and CRLF', async () => {
  const bytes = new TextEncoder().encode('data: {"delta":"中文"}\r\n\r\ndata: {"done":true,"content":"# 完整报告"}\r\n\r\n')
  const r = new Response(new ReadableStream({ start(c) { for (const b of bytes) c.enqueue(new Uint8Array([b])); c.close() } }), { headers: { 'content-type': 'text/event-stream' } })
  let partial = ''; assert.equal(await consumeReportStream(r, p => { if (p.delta) partial += p.delta }), '# 完整报告'); assert.equal(partial, '中文')
})
test('partial EOF is an interruption and cannot trigger another model call', async () => {
  await assert.rejects(consumeReportStream(response(['data: {"delta":"部分报告"}\n\n'])), e => e instanceof ReportStreamError && !e.canFallback && /中断/.test(e.message))
})
test('only explicit unsupported endpoints allow fallback', async () => {
  for (const status of [404, 405, 501]) await assert.rejects(consumeReportStream(new Response('{}', { status })), e => e.canFallback === true)
  for (const status of [401, 403, 422, 429, 500, 503]) await assert.rejects(consumeReportStream(new Response('{"message":"拒绝请求"}', { status })), e => e.canFallback === false && e.message === '拒绝请求')
})
test('provider errors, malformed data and empty done fail closed', async () => {
  for (const s of ['data: {"error":"供应商错误"}\n\n', 'data: {invalid}\n\n', 'data: {"done":true}\n\n']) await assert.rejects(consumeReportStream(response([s])), e => !e.canFallback)
})
test('a done frame without trailing delimiter is consumed', async () => {
  assert.equal(await consumeReportStream(response([': heartbeat\n\ndata:{"delta":"报告"}\n\ndata: {"done":true}'])), '报告')
})
test('stream is cancelled and unlocked after completion', async () => {
  let cancelled = 0
  const r = new Response(new ReadableStream({ start(c) { c.enqueue(new TextEncoder().encode('data: {"done":true,"content":"报告"}\n\n')) }, cancel() { cancelled++ } }), { headers: { 'content-type': 'text/event-stream' } })
  await consumeReportStream(r); assert.equal(cancelled, 1); assert.equal(r.body.locked, false)
})

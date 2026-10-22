export class ReportStreamError extends Error {
  constructor(message, canFallback = false) { super(message); this.name = 'ReportStreamError'; this.canFallback = canFallback }
}

/** A report is complete only after a done event. Partial output stays with the caller. */
export async function consumeReportStream(response, onEvent = () => {}) {
  if (!response.ok) {
    let message = `HTTP ${response.status}`
    try { const body = await response.json(); if (body?.message) message = body.message } catch {}
    throw new ReportStreamError(message, [404, 405, 501].includes(response.status))
  }
  if (!(response.headers.get('content-type') || '').toLowerCase().includes('text/event-stream')) {
    throw new ReportStreamError('服务未返回流式报告，请检查接口配置')
  }
  const reader = response.body?.getReader()
  if (!reader) throw new ReportStreamError('当前浏览器不支持流式读取')
  const decoder = new TextDecoder()
  let buffer = '', content = '', finished = false
  function event(frame) {
    const lines = frame.replace(/\r\n/g, '\n').split('\n').filter(line => line.startsWith('data:'))
    if (!lines.length) return
    let payload
    try { payload = JSON.parse(lines.map(line => line.slice(5).replace(/^ /, '')).join('\n')) }
    catch { throw new ReportStreamError('分析响应格式无效，已保留生成内容') }
    if (payload.error) throw new ReportStreamError(String(payload.error))
    if (typeof payload.delta === 'string') content += payload.delta
    if (payload.done) {
      if (typeof payload.content === 'string') content = payload.content
      if (!content.trim()) throw new ReportStreamError('分析未返回有效内容')
      finished = true
    }
    onEvent(payload)
  }
  try {
    while (!finished) {
      const { value, done } = await reader.read()
      buffer += done ? decoder.decode() : decoder.decode(value, { stream: true })
      let separator
      while (!finished && (separator = /\r?\n\r?\n/.exec(buffer))) {
        event(buffer.slice(0, separator.index))
        buffer = buffer.slice(separator.index + separator[0].length)
      }
      if (done) { if (!finished && buffer.trim()) event(buffer); break }
    }
    if (!finished) throw new ReportStreamError('连接中断，报告尚未完成')
    return content
  } finally {
    await reader.cancel().catch(() => {})
    reader.releaseLock()
  }
}

import test from 'node:test'
import assert from 'node:assert/strict'
import { renderReportMarkdown } from '../utils/report-markdown.mjs'

test('reports strip executable HTML and embedded resources', () => {
  const html = renderReportMarkdown('<img src="https://example.org/tracker" onerror="alert(1)"><script>alert(1)</script><svg onload="alert(1)"></svg><iframe src="https://example.org"></iframe>')
  assert.doesNotMatch(html, /<img|<script|<svg|<iframe|onerror|onload/)
})

test('reports reject unsafe and protocol-relative links', () => {
  for (const url of ['javascript:alert(1)', 'data:text/html,test', '//example.org', 'jav&#x61;script:alert(1)']) {
    assert.doesNotMatch(renderReportMarkdown(`<a href="${url}">link</a>`), /href=/)
  }
})

test('reports preserve safe Markdown and HTTPS links without opening new windows', () => {
  const html = renderReportMarkdown('# 中文报告\n\n**重点** [来源](https://example.org)\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n```js\nconst x = "<script>"\n```')
  for (const pattern of [/<h1>中文报告<\/h1>/, /<strong>重点<\/strong>/, /href="https:\/\/example.org"/, /<table>/, /<code/, /&lt;script&gt;/]) assert.match(html, pattern)
  assert.doesNotMatch(html, /target=/)
})

test('every partial streaming prefix remains safe', () => {
  const input = '<img src=x onerror="alert(1)"><a href="javascript:alert(1)">link</a>'
  for (let end = 1; end <= input.length; end++) {
    assert.doesNotMatch(renderReportMarkdown(input.slice(0, end)), /<(?:img|script|svg|iframe)\b|<a\b[^>]*href="javascript:/i)
  }
})

test('empty reports return empty HTML', () => {
  assert.equal(renderReportMarkdown(''), '')
})

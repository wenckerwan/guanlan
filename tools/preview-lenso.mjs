import { spawn } from 'node:child_process'
import { createServer } from 'node:http'
import { once } from 'node:events'
import { fileURLToPath } from 'node:url'

const host = '127.0.0.1'
const webPort = Number(process.argv[2] ?? 3118)
if (!Number.isInteger(webPort) || webPort < 1024 || webPort > 65535) {
  throw new Error('Pass a preview port between 1024 and 65535.')
}
const origin = `http://${host}:${webPort}`
const title = '从实践到认识：一道材料题怎样展开'
const topics = ['提取材料线索', '区分概念', '组织答案']
const paragraph = '分析材料时，先找出主体、行动与结果，再判断每段对应的原理。作答时用材料里的具体事例支持判断，并用一句话说明事例与原理之间的关系。'
const article = {
  slug: 'ui-preview', title, summary: paragraph, priority: 'A', category: '阅读样板',
  outline: topics.map((title, index) => ({ title, level: index === 2 ? 3 : 2 })),
  html: topics.map((topic, index) => {
    const tag = index === 2 ? 'h3' : 'h2'
    const table = index === 1
      ? `<table><caption>材料分析对照</caption><thead><tr>${['材料线索', '对应概念', '判断依据', '常见误区', '答题句式', '复核方式'].map(label => `<th scope="col">${label}</th>`).join('')}</tr></thead><tbody><tr>${['基层走访', '实践和认识', '调查获得新证据', '只写理论不扣材料', '先概括再举例', '核对题目设问'].map(value => `<td>${value}</td>`).join('')}</tr></tbody></table>`
      : ''
    return `<${tag} id="toc-${index}">${topic}</${tag}><p>${paragraph}</p>${table}<blockquote>${paragraph}</blockquote><p>${paragraph}</p>`
  }).join(''),
}
const paper = {
  paper: { pid: 'ui-preview', year: 2026, label: '本地样板', questionCount: 3, totalScore: 4, answeredCount: 3 },
  questions: topics.map((topic, index) => ({
    id: index + 1, no: index + 1, type: index === 1 ? 'multi' : 'single',
    typeCn: index === 1 ? '多选题' : '单选题', score: index === 1 ? 2 : 1,
    moduleName: '马克思主义基本原理', kaodian: topic, objective: true,
    stem: `围绕“${topic}”，下列选项中哪些体现了从实际材料出发的作答方式？`,
    material: index === 0 ? paragraph : '',
    options: { A: '结合调查记录核实结论', B: '仅按题目长度判断答案', C: '比较材料中的不同证据' },
    answer: index === 1 ? 'AC' : 'A',
    analysis: '关键是让判断与材料中的证据对应。',
  })),
}
const data = new Map([
  ['/api/v1/home', { hotspots: [{ ...article, level: 'A', period: '2026-11', url: '/hotspots/ui-preview' }], documents: [], subjects: [], stats: { papers: 1, questions: 3, hotspots: 2 } }],
  ['/api/v1/hotspots/ui-preview', article],
  ['/api/v1/hotspots/ui-preview-no-outline', { ...article, outline: [] }],
  ['/api/v1/papers/ui-preview', paper],
  ['/api/v1/papers/ui-preview-empty', { paper: { ...paper.paper, questionCount: 0 }, questions: [] }],
])
const api = createServer((request, response) => {
  const payload = data.get(new URL(request.url, origin).pathname)
  response.writeHead(request.method === 'GET' && payload !== undefined ? 200 : 404, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': origin,
  })
  response.end(JSON.stringify({ data: request.method === 'GET' ? payload : undefined }))
})
api.listen(0, host)
await once(api, 'listening')
const apiBase = `http://${host}:${api.address().port}/api/v1`
const app = spawn(process.execPath, ['.output/server/index.mjs'], {
  cwd: fileURLToPath(new URL('../apps/web/', import.meta.url)),
  stdio: 'inherit',
  env: { ...process.env, NITRO_HOST: host, NITRO_PORT: String(webPort), NUXT_API_INTERNAL_BASE: apiBase, NUXT_PUBLIC_API_BASE: apiBase, NUXT_PUBLIC_COMMENTS_ENABLED: 'false' },
})
for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => app.kill(signal))
app.once('exit', code => {
  api.close()
  process.exitCode = code ?? 0
})

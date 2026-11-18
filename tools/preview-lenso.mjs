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
const subjectsMode = process.argv[3] ?? 'normal'
if (!['normal', 'empty', 'error'].includes(subjectsMode)) throw new Error('Subjects mode must be normal, empty, or error.')
const articlesMode = process.argv[4] ?? 'normal'
if (!['normal', 'empty', 'error'].includes(articlesMode)) throw new Error('Articles mode must be normal, empty, or error.')
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
  paper: { pid: 'ui-preview', year: 2026, label: '本地样板', kind: 'modern', questionCount: 3, totalScore: 4, answeredCount: 3 },
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
const olderPaper = { ...paper.paper, pid: 'ui-preview-1994', year: 1994, kind: 'split', label: '文科 · 本地样板' }
const previewMock = {
  slug: 'ui-preview', title: '考研政治模拟卷 · 本地样板', summary: paragraph,
  questionCount: 3, totalScore: 13, durationMinutes: 180, answeredCount: 3,
}
const mockQuestions = [
  { ...paper.questions[0], no: 1 },
  { ...paper.questions[1], no: 17 },
  { ...paper.questions[2], no: 34, type: 'analyse', typeCn: '分析题', score: 10, options: {}, answer: null },
]
const subjects = [
  {
    slug: 'ui-preview', short: '马原', name: '马克思主义基本原理', tone: 'jade',
    detail: '从实践、认识和社会发展规律梳理概念。', intro: paragraph, hotspots: [],
    chapters: topics.map((topic, index) => ({
      id: `preview-${index + 1}`,
      title: index === 1 ? '区分概念：从认识的来源、发展过程和检验标准理解实践与认识的关系' : topic,
      summary: paragraph,
      points: index === 2 ? [] : [{ title: `${topic}的复习要点`, summary: paragraph }],
    })),
  },
  { slug: 'ui-preview-empty', short: '史纲', name: '中国近现代史纲要', tone: 'gold', detail: '本地空章节样板。', intro: '中国近现代史学习资料。', hotspots: [], chapters: [] },
]
const articleLists = ['/hotspots', '/predictions', '/analysis']
const articles = [
  { ...article, period: '2026-11', layer: '真题反推', release: true },
  { title: '如何从政策材料中区分政策目标、实施路径与阶段成果', slug: 'ui-preview-locked', summary: paragraph, period: '2026-11', layer: '真题反推', category: article.category, priority: 'S', locked: true },
]
const student = { code: 'ui-preview', name: '样板考生', relation: '本人', itemCount: 1, moduleCounts: { 马原: 1 }, errorTypes: { 概念混淆: 1 } }
const mistake = {
  id: 1, module: '马原', chapter: '实践与认识', sourceNo: '样题 01', kaodian: '实践是认识的基础',
  stem: '结合调查研究的过程，哪一项体现了实践对认识的作用？',
  options: [{ label: 'A', text: '实地调查为形成和检验认识提供依据', mark: 'missed' }, { label: 'B', text: '判断只依靠已有结论', mark: 'chosen' }],
  myAnswer: 'B', correctAnswer: 'A', qType: '单选题', errorType: '概念混淆', action: '区分认识的来源与检验标准。',
}
const mistakePath = '/api/v1/mistakes/students'
const reviewPath = `${mistakePath}/${student.code}/review-summary`
const handbook = { id: 1, studentCode: student.code, module: '马原', title: '实践与认识提分手册', sectionCount: 1, sections: [{ title: '复习建议', html: `<p>${paragraph}</p>` }] }
const previewToken = 'lenso-preview-local'
const bankId = 'current-affairs-2026'
const practicePath = `/api/v1/practice/banks/${bankId}`
const practiceStatePath = `/api/v1/study/practice/banks/${bankId}/state`
const attemptPath = '/api/v1/study/practice/attempts'
const favoritesPath = '/api/v1/study/favorites'
const questionStates = new Map()
const favoriteIds = new Set()
const practiceQuestions = Array.from({ length: 8 }, (_, index) => {
  const { type, stem, options, answer, analysis } = paper.questions[index % paper.questions.length]
  return {
    type, stem, options: { ...options, D: '忽略材料中不支持结论的事实' }, answer, analysis,
    questionRef: `PREVIEW-${index + 1}`, questionVersion: '1',
    module: index < 4 ? 'xs' : 'sx', event: '本地预览例题', knowledge: paragraph,
  }
})
const data = new Map([
  ...articleLists.flatMap(path => [[`/api/v1${path}`, articlesMode === 'empty' ? [] : articles], [`/api/v1${path}/ui-preview`, article]]),
  ['/api/v1/subjects', subjectsMode === 'empty' ? [] : subjects],
  ['/api/v1/home', { hotspots: [{ ...article, level: 'A', period: '2026-11', url: '/hotspots/ui-preview' }], documents: [], subjects: [], stats: { papers: 1, questions: 3, hotspots: 2 } }],
  ['/api/v1/hotspots/ui-preview-no-outline', { ...article, outline: [] }],
  ['/api/v1/papers/ui-preview', paper],
  ['/api/v1/papers/ui-preview-1994', { ...paper, paper: olderPaper }],
  ['/api/v1/papers/modules', [{ module: 'marx', name: '马原', count: 3 }, { module: 'history', name: '史纲', count: 3 }]],
  ['/api/v1/mocks', [previewMock]],
  ['/api/v1/mocks/ui-preview', { mock: previewMock, questions: mockQuestions }],
  ['/api/v1/papers/ui-preview-empty', { paper: { ...paper.paper, questionCount: 0 }, questions: [] }],
])
const searchItems = [
  { type: 'hotspot', title, snippet: paragraph, url: '/hotspots/ui-preview', meta: '阅读样板', matchLocation: 'body' },
  { type: 'paper', title: '2026 年考研政治真题（本地样板）', snippet: '单选题、多选题与作答解析。', url: '/papers/ui-preview', meta: '2026', matchLocation: 'summary' },
]
const api = createServer(async (request, response) => {
  const url = new URL(request.url, origin)
  const isSearch = request.method === 'GET' && url.pathname === '/api/v1/search'
  const keyword = url.searchParams.get('q')
  const type = url.searchParams.get('type')
  const items = keyword === '实践与认识' ? searchItems.filter(item => !type || item.type === type) : []
  const signedIn = request.headers.authorization === `Bearer ${previewToken}`
  let payload = isSearch ? { items, total: items.length, groups: Object.fromEntries(items.map(item => [item.type, 1])) } : data.get(url.pathname)
  if (url.pathname === '/api/v1/papers') {
    const module = url.searchParams.get('module')
    payload = module === 'marx' ? [paper.paper] : module === 'history' ? [olderPaper] : module ? [] : [paper.paper, olderPaper]
  }
  if (url.pathname === practicePath) {
    const questions = signedIn ? practiceQuestions.map(({ answer, ...question }) => question) : practiceQuestions.slice(0, 3)
    payload = { bankId, contentVersion: '1', questionCount: questions.length, questions }
  }
  if (signedIn && url.pathname === practiceStatePath) payload = { questions: Object.fromEntries(questionStates) }
  if (signedIn && url.pathname === favoritesPath) payload = [...favoriteIds].map(targetId => ({ targetId }))
  const attempt = signedIn && request.method === 'POST' && url.pathname === attemptPath
  const favorite = signedIn && request.method === 'PUT' && url.pathname === favoritesPath
  if (attempt || favorite) {
    try {
      let body = ''
      for await (const chunk of request) body += chunk
      const event = JSON.parse(body)
      if (attempt) {
        const question = practiceQuestions.find(item => item.questionRef === event.questionRef)
        if (!question) throw new Error('Unknown preview question')
        const isRight = event.chosen.join('') === question.answer
        const questionState = {
          attemptCount: (questionStates.get(event.questionRef)?.attemptCount ?? 0) + 1,
          lastChosen: event.chosen, lastIsRight: isRight, lastCorrect: question.answer,
        }
        questionStates.set(event.questionRef, questionState)
        payload = { isRight, correct: question.answer, duplicate: false, questionState }
      } else {
        if (event.favorited) favoriteIds.add(event.targetId)
        else favoriteIds.delete(event.targetId)
        payload = { favorited: event.favorited }
      }
    } catch { payload = undefined }
  }
  if (url.pathname === '/api/v1/auth/me' && signedIn) payload = { user: { id: 1, displayName: student.name, role: 'user', status: 'active' } }
  if (url.pathname === mistakePath) payload = signedIn ? [student] : []
  if (signedIn && url.pathname === `${mistakePath}/${student.code}/items`) {
    const visible = !url.searchParams.get('module') || url.searchParams.get('module') === mistake.module ? [mistake] : []
    payload = { student, items: visible, total: visible.length, page: 1, perPage: 20 }
  }
  if (signedIn && url.pathname === reviewPath) {
    payload = { total: 1, new: 0, reviewing: 1, mastered: 0, snoozed: 0, dueToday: 0, overdue: 1, accuracy: 0.5, reviewCount: 2 }
  }
  if (signedIn && url.pathname === `${mistakePath}/${student.code}/handbooks`) payload = [handbook]
  if (signedIn && url.pathname === '/api/v1/mistakes/handbooks/1') payload = handbook
  const review = signedIn && request.method === 'POST' && url.pathname === '/api/v1/mistakes/items/1/review'
  if (review) {
    try {
      let body = ''
      for await (const chunk of request) body += chunk
      const isCorrect = JSON.parse(body).chosen === mistake.correctAnswer
      payload = { isCorrect, nextReviewAt: '2026-11-21', status: 'reviewing', reviewCount: 3, correctCount: isCorrect ? 2 : 1 }
    } catch { payload = undefined }
  }
  const articleList = articleLists.some(path => url.pathname === `/api/v1${path}`)
  const lockedArticle = articleLists.some(path => url.pathname === `/api/v1${path}/ui-preview-locked`)
  const unavailable = (isSearch && keyword === '请求失败') || (url.pathname === '/api/v1/subjects' && subjectsMode === 'error') || (articleList && articlesMode === 'error')
  const allowed = (request.method === 'GET' || review || attempt || favorite) && payload !== undefined
  const privatePath = url.pathname.startsWith(`${mistakePath}/`) || ['/api/v1/mistakes/items/1/review', '/api/v1/mistakes/handbooks/1', practiceStatePath, attemptPath, favoritesPath].includes(url.pathname)
  const status = request.method === 'OPTIONS' ? 204 : unavailable ? 503 : lockedArticle || (privatePath && !signedIn) ? 403 : allowed ? 200 : 404
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': origin,
    'Access-Control-Allow-Headers': 'authorization, content-type',
    'Access-Control-Allow-Methods': 'GET, POST, PUT, OPTIONS',
  })
  response.end(JSON.stringify({ data: allowed ? payload : undefined }))
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

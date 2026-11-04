import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'

const web = new URL('../', import.meta.url)
const api = new URL('../../api/', import.meta.url)
const read = (root, file) => fs.readFileSync(new URL(file, root), 'utf8')
const controller = read(api, 'src/Controller/MistakeController.php')
const routes = read(api, 'config/routes.php')
const page = read(web, 'pages/mistakes/[code].vue')
const plugin = read(web, 'plugins/remove-legacy-ai-config.client.ts')
const retiredMethods = ['requestAIAnalysis', 'requestAIAnalysisStream', 'testAIConnection', 'chatTestAIConnection', 'saveAnalysisReport', 'analysisReports', 'analysisReport', 'deleteAnalysisReport', 'getAIConfig', 'aiService', 'wantsImport', 'importUploadedMarkdown']

test('retired AI page, provider service and frontend helpers are absent', () => {
  for (const [root, file] of [[web, 'pages/mistakes/analyze/[code].vue'], [api, 'src/Service/AIAnalysisService.php'], [web, 'utils/report-stream.mjs'], [web, 'utils/report-markdown.mjs']]) {
    assert.equal(fs.existsSync(new URL(file, root)), false, file)
  }
})

test('AI controller methods and imports are removed, not merely hidden', () => {
  for (const name of retiredMethods) assert.doesNotMatch(controller, new RegExp(`function\\s+${name}\\s*\\(`))
  assert.doesNotMatch(controller, /AIAnalysisService|MistakeAnalysisReport|apiKey|provider|aiReportDailyLimit/)
})

test('all AI configuration, testing, generation and report routes are removed', () => {
  assert.doesNotMatch(routes, /ai-analysis|ai-config|\/ai\/test|\/ai\/chat-test|analysis-reports/)
  for (const name of retiredMethods) assert.ok(!routes.includes(`'${name}'`), name)
})

test('ordinary mistakes, manual reports and review routes and methods remain', () => {
  for (const name of ['students', 'show', 'items', 'handbooks', 'handbook', 'detail', 'updateAction', 'deleteItem', 'reviewSummary', 'submitReview', 'updateReviewStatus']) {
    assert.match(controller, new RegExp(`function\\s+${name}\\s*\\(`), name)
    assert.ok(routes.includes(`'${name}'`), name)
  }
  for (const name of ['mistakeProfile', 'saveMistakeProfile']) assert.ok(routes.includes(`'${name}'`), name)
  assert.match(page, /\/detail/)
  assert.match(page, /showAnalysis/)
  assert.match(page, /analysis\?\.html/)
  assert.match(page, /handbooks/)
  assert.doesNotMatch(page, /aiReports|openAiReport|AI 分析|\/mistakes\/analyze|analysis-reports|report-markdown/)
})

test('historical AI report model and migrations are kept; no deletion migration added', () => {
  assert.equal(fs.existsSync(new URL('src/Model/MistakeAnalysisReport.php', api)), true)
  const migrations = fs.readdirSync(new URL('migrations/', api))
  const reportMigrations = migrations.filter(name => /analysis_report/i.test(name))
  assert.ok(reportMigrations.length > 0)
  assert.ok(reportMigrations.every(name => !/drop|delete|remove/i.test(name)))
})

function runPlugin(storage) {
  const executable = plugin.replace('export default ', '')
  vm.runInNewContext(executable, { defineNuxtPlugin: setup => setup(), window: { localStorage: storage } })
}

test('client startup deletes only the legacy credential key, without reading or parsing it', () => {
  const values = new Map([['ai_analysis_config', '{malformed secret'], ['auth_token', 'untouched'], ['mistake-progress', 'keep']])
  const calls = []
  const storage = {
    removeItem(key) { calls.push(key); values.delete(key) },
    getItem() { throw new Error('Credentials must not be read') },
    clear() { throw new Error('Unrelated storage must not be cleared') },
  }
  runPlugin(storage)
  runPlugin(storage)
  assert.deepEqual(calls, ['ai_analysis_config', 'ai_analysis_config'])
  assert.equal(values.has('ai_analysis_config'), false)
  assert.equal(values.get('auth_token'), 'untouched')
  assert.equal(values.get('mistake-progress'), 'keep')
  assert.doesNotMatch(plugin, /useAuth|fetch\(|JSON\.parse|\.setItem\(|\.clear\(/)
})

test('blocked or unavailable local storage never aborts application startup', () => {
  assert.doesNotThrow(() => runPlugin({ removeItem() { throw new Error('SecurityError') } }))
  assert.doesNotThrow(() => runPlugin(undefined))
})

test('retired AI entitlement is no longer advertised or returned', () => {
  assert.doesNotMatch(read(api, 'src/Support/UserGroup.php'), /aiReportDailyLimit/)
  assert.doesNotMatch(read(web, 'types/api.ts'), /aiReportDailyLimit/)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { build } from 'esbuild'
import vm from 'node:vm'
import { createRequire } from 'node:module'
const require = createRequire(import.meta.url)

async function page(error, article = null) {
  const source = await readFile(new URL('../pages/analysis/[slug].vue', import.meta.url), 'utf8')
  const script = source.split('<script setup lang="ts">')[1].split('</script>')[0].replace(/^import .*$/gm, '')
  const compiled = await build({ stdin: { contents: `(async () => {${script}; return { archived, article }; })()`, loader: 'ts' }, write: false, platform: 'node', format: 'cjs' })
  const navigations = []
  const box = { require, computed: require('vue').computed, useRoute: () => ({ params: { slug: '2012-2026-45208c3f' } }), useApiFetch: async () => ({ data: { value: article }, error: { value: error } }), useHead() {}, navigateTo: async (...args) => navigations.push(args) }
  const state = await vm.runInNewContext(compiled.outputFiles[0].text, box)
  return { state, source, navigations }
}

test('archived analysis offers an explicit normal release link and preserves historical context', async () => {
  const { state, source, navigations } = await page({ statusCode: 410, data: { code: 'ANALYSIS_VERSION_ARCHIVED', replacementSlug: '2012-2026-649d1f95' } })
  assert.equal(state.archived.value.replacementUrl, '/analysis/2012-2026-649d1f95')
  assert.match(source, /此版本已下架/)
  assert.match(source, /历史评论.*旧稿.*发行版/)
  assert.match(source, /v-else-if="archived"/)
  assert.match(source, /:to="archived.replacementUrl"/)
  assert.match(source, /CommentSection v-if="article"/)
  assert.deepEqual(navigations, [])
})

test('hidden or missing release gives an archive explanation without a replacement link', async () => {
  const { state } = await page({ statusCode: 410, data: { code: 'ANALYSIS_VERSION_ARCHIVED' } })
  assert.equal(state.archived.value.replacementUrl, null)
})

test('unknown errors stay ordinary not-found and visible old content stays readable', async () => {
  assert.equal((await page({ statusCode: 404 })).state.archived.value, null)
  assert.equal((await page({ statusCode: 410, data: { code: 'OTHER' } })).state.archived.value, null)
  const { state } = await page(null, { slug: 'old', title: 'Old' })
  assert.equal(state.article.value.title, 'Old')
})

test('release detail quota denial keeps the existing login flow', async () => {
  const { navigations } = await page({ statusCode: 403 })
  assert.equal(navigations[0][0].path, '/analysis')
  assert.equal(navigations[0][0].query.login, 1)
})

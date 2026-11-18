import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizeAppearance } from '../utils/appearance.mjs'
import { readFile } from 'node:fs/promises'
import { transform } from 'esbuild'
import { computed, ref } from 'vue'

test('appearance keeps explicit preferences and safely defaults invalid cookies to system', () => {
  for (const value of ['light', 'dark', 'system']) assert.equal(normalizeAppearance(value), value)
  for (const value of [undefined, null, '', 'DARK', 'auto', {}, '<script>']) {
    assert.equal(normalizeAppearance(value), 'system')
  }
})

test('actual appearance composable reads SSR preference and persists normalized changes to the shared cookie', async () => {
  const source = await readFile(new URL('../composables/useAppearance.ts', import.meta.url), 'utf8')
  const compiled = await transform(source.replace(/^import .*$/gm, '').replace('export function', 'function'), { loader: 'ts' })
  const create = new Function('computed', 'useCookie', 'normalizeAppearance', compiled.code + '\nreturn useAppearance')
  const cookie = ref('dark')
  let options
  const useAppearance = create(computed, (name, settings) => {
    assert.equal(name, 'guanlan.appearance')
    options = settings
    return cookie
  }, normalizeAppearance)
  const first = useAppearance()
  const second = useAppearance()
  assert.equal(first.appearance.value, 'dark')
  assert.equal(options.path, '/')
  assert.equal(options.sameSite, 'lax')
  assert.equal(options.default(), 'system')
  first.appearance.value = 'light'
  assert.equal(cookie.value, 'light')
  assert.equal(second.appearance.value, 'light')
  second.appearance.value = 'invalid'
  assert.equal(first.appearance.value, 'system')
  assert.equal(cookie.value, 'system')
})

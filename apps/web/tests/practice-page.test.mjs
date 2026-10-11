import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'

test('practice route binds its nested component explicitly for Nuxt rendering', () => {
  const source = fs.readFileSync(new URL('../pages/practice/current-affairs.vue', import.meta.url), 'utf8')
  const { descriptor, errors } = parse(source, { filename: 'current-affairs.vue' })
  assert.deepEqual(errors, [])
  const script = compileScript(descriptor, { id: 'practice-route' })
  assert.ok(script.bindings.CurrentAffairsPractice, 'nested component must be imported or registered in script setup')
})

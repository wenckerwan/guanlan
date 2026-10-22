import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { build } from 'esbuild'

test('reconnect storage failure preserves the draft and never claims it was saved', async () => {
  const path = 'components/history/ui/HistoryExplorer.vue'
  const source = (await readFile(path, 'utf8')).split('<script setup lang="ts">')[1].split('</script>')[0]
  const { outputFiles } = await build({
    stdin: { contents: source + '\nexport { handleNetwork, retryStudyRestore, ready, studyWritable, saveStatus, study, studyRestoreError };', resolveDir: fileURLToPath(new URL('../components/history/ui/', import.meta.url)), loader: 'ts' },
    bundle: true, write: false, platform: 'node', format: 'cjs', external: ['vue'],
    loader: { '.css': 'empty', '.vue': 'empty' },
  })
  let loads = 0, storageFails = true, persisted
  const box = {
    module: { exports: {} }, exports: {},
    require: name => name === 'vue' ? { ...createRequire(import.meta.url)('vue'), onMounted() {}, onBeforeUnmount() {}, watch() {} } : createRequire(import.meta.url)(name),
    defineProps: () => ({ repository: {}, router: {}, studyRepository: {
      async save(state) { if (storageFails) throw Error('IndexedDB quota exceeded'); persisted = state; return state },
      async load() { loads++; assert.ok(persisted, 'persist the in-memory draft before reloading'); return persisted },
    } }),
    navigator: { onLine: true }, Error, setTimeout: () => 0, clearTimeout() {},
  }
  vm.runInNewContext(outputFiles[0].text, box)
  const ui = box.module.exports
  ui.ready.value = true; ui.studyWritable.value = true
  ui.study.value.notes.A = 'unsaved draft'
  await ui.handleNetwork()
  assert.equal(ui.study.value.notes.A, 'unsaved draft')
  assert.equal(loads, 0)
  assert.match(ui.saveStatus.value, /保存失败/)
  assert.equal(ui.studyWritable.value, false)
  assert.match(ui.studyRestoreError.value, /quota/)
  storageFails = false
  await ui.retryStudyRestore({ notifyUser: false })
  assert.equal(loads, 1)
  assert.equal(persisted.notes.A, 'unsaved draft')
  assert.equal(ui.study.value.notes.A, 'unsaved draft')
  assert.equal(ui.studyWritable.value, true)
  assert.equal(ui.studyRestoreError.value, '')
})

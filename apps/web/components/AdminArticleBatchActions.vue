<script setup lang="ts">
import { computed, ref, watch } from 'vue'
type Item = { id: number; title: string; revision?: number }
type Result = { id: number; ok: boolean; status: number; message?: string; revision?: number }
const props = defineProps<{ kind: 'hotspot' | 'analysis'; items: Item[]; busy?: boolean }>()
const emit = defineEmits<{ updated: [] }>()
const { request } = useAuth()
const opened = ref(false), selected = ref<number[]>([]), saving = ref(false), message = ref(''), errors = ref<Result[]>([])
const blocked = computed(() => props.busy || saving.value)
const all = computed({ get: () => props.items.length > 0 && selected.value.length === props.items.length, set: checked => { selected.value = checked ? props.items.map(item => item.id) : [] } })
watch(() => props.items, () => { selected.value = [] })
async function apply(status: 'published' | 'hidden') {
  if (blocked.value || !selected.value.length) return
  if (!window.confirm(`将本页选中的 ${selected.value.length} 篇文章设为${status === 'hidden' ? '隐藏' : '发布'}？版本冲突的条目会保留原状态。`)) return
  saving.value = true; message.value = ''; errors.value = []
  try {
    const items = props.items.filter(item => selected.value.includes(item.id)).map(item => ({ id: item.id, expectedRevision: item.revision ?? 1 }))
    const result = await request<{ results: Result[]; succeeded: number; failed: number }>(`/admin/articles/${props.kind}/status-batch`, { method: 'POST', body: { status, items } })
    if (!Array.isArray(result.results) || !Number.isInteger(result.succeeded) || !Number.isInteger(result.failed) || result.results.length !== items.length || result.succeeded + result.failed !== items.length) throw new Error('Invalid batch result')
    message.value = `成功 ${result.succeeded} 篇，失败 ${result.failed} 篇`
    errors.value = result.results.filter(item => !item.ok)
    selected.value = []; emit('updated')
  } catch (error) { message.value = (error as { data?: { message?: string } })?.data?.message || '无法确认批量结果，请刷新列表后检查。' }
  finally { saving.value = false }
}
</script>

<template>
  <section class="batch-actions">
    <button class="ghost-button" type="button" :disabled="blocked" @click="opened = !opened">批量状态管理</button>
    <div v-if="opened" class="batch-panel">
      <p class="admin-meta">仅处理当前页选中的文章；每篇独立检查修订号，失败条目不会自动重试。</p>
      <label><input v-model="all" type="checkbox" :disabled="blocked || !items.length" aria-label="选择本页全部文章" />选择本页全部文章</label>
      <div class="batch-selection"><label v-for="item in items" :key="item.id"><input v-model="selected" type="checkbox" :value="item.id" :disabled="blocked" :aria-label="`选择 ${item.title}`" />{{ item.title }}</label></div>
      <div class="batch-buttons"><button class="ghost-button" type="button" :disabled="blocked || !selected.length" @click="apply('published')">批量发布</button><button class="ghost-button" type="button" :disabled="blocked || !selected.length" @click="apply('hidden')">批量隐藏</button><span>已选 {{ selected.length }} 篇</span></div>
    </div>
    <p v-if="message" class="admin-meta" role="status">{{ message }}</p>
    <ul v-if="errors.length" class="batch-errors" role="alert"><li v-for="item in errors" :key="item.id">#{{ item.id }}：{{ item.message || `操作失败（${item.status}）` }}</li></ul>
  </section>
</template>

<style scoped>
.batch-panel { display: grid; gap: 12px; margin-top: 12px; padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: white; }
.batch-panel label { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; overflow-wrap: anywhere; }
.batch-selection { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 8px; max-height: 260px; overflow: auto; }
.batch-buttons { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.batch-errors { color: var(--brand); font-size: 13px; padding-left: 20px; overflow-wrap: anywhere; }
</style>

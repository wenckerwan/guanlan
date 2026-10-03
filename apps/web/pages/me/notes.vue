<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Pencil, Trash2 } from 'lucide-vue-next'
import type { Note } from '~/types/api'

const { request, restore } = useAuth()
const items = ref<Note[]>([])
const loading = ref(true)
const editingId = ref<number | null>(null)
const draft = ref('')
const saving = ref(false)

onMounted(async () => {
  restore()
  try {
    items.value = await request<Note[]>('/study/notes')
  } finally {
    loading.value = false
  }
})

async function remove(id: number) {
  await request(`/study/notes/${id}`, { method: 'DELETE' })
  items.value = items.value.filter((item) => item.id !== id)
  if (editingId.value === id) cancelEdit()
}

function startEdit(item: Note) {
  editingId.value = item.id
  draft.value = item.content
}

function cancelEdit() {
  editingId.value = null
  draft.value = ''
}

async function saveEdit(id: number) {
  const content = draft.value.trim()
  if (!content || saving.value) return
  saving.value = true
  try {
    const updated = await request<Note>(`/study/notes/${id}`, { method: 'PATCH', body: { content } })
    const index = items.value.findIndex((item) => item.id === id)
    if (index >= 0) items.value[index] = updated
    cancelEdit()
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow">笔记</div>
        <h1>我的笔记</h1>
        <p>共 {{ items.length }} 条。</p>
      </section>

      <ul class="note-list">
        <li v-for="item in items" :key="item.id">
          <header>
            <span class="record-type">{{ targetTypeLabel(item.targetType) }}</span>
            <strong>{{ item.title || item.targetId }}</strong>
            <time>{{ item.createdAt.slice(0, 10) }}</time>
            <button type="button" class="icon-button" aria-label="编辑笔记" @click="startEdit(item)"><Pencil :size="14" /></button>
            <button type="button" class="icon-button" aria-label="删除笔记" @click="remove(item.id)"><Trash2 :size="14" /></button>
          </header>
          <div v-if="editingId === item.id" class="note-edit">
            <textarea v-model="draft" rows="4" maxlength="20000" aria-label="笔记内容"></textarea>
            <div class="note-edit-actions">
              <button type="button" class="primary-button small" :disabled="saving || !draft.trim()" @click="saveEdit(item.id)">{{ saving ? '保存中…' : '保存' }}</button>
              <button type="button" class="ghost-button small" :disabled="saving" @click="cancelEdit">取消</button>
            </div>
          </div>
          <p v-else>{{ item.content }}</p>
        </li>
      </ul>
      <div v-if="!loading && !items.length" class="empty-state">还没有笔记。</div>
      <NuxtLink class="back-link" to="/me">返回个人中心</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.note-edit textarea { width:100%; padding:8px 11px; border:1px solid var(--line,#dcdfe4); border-radius:6px; font-size:13px; font-family:inherit; resize:vertical; box-sizing:border-box; }
.note-edit textarea:focus { outline:none; border-color:var(--jade,#2f8f6b); }
.note-edit-actions { display:flex; gap:8px; margin-top:8px; }
.note-edit-actions button { padding:6px 14px; min-height:34px; font-size:13px; border-radius:6px; cursor:pointer; }
</style>
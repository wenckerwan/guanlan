<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { MessageSquare, Pin, Send, Trash2 } from 'lucide-vue-next'
import UserGroupBadge from '~/components/UserGroupBadge.vue'

const props = defineProps<{
  articleType: 'analysis' | 'hotspot' | 'prediction'
  slug: string
}>()

const { isLoggedIn, request, user } = useAuth()

type CommentUser = { id: number; name: string; role: string; userGroup: string }
type CommentRow = {
  id: number
  floor: number
  content: string
  status: string
  pinned: boolean
  createdAt: string
  isMine: boolean
  replyTo?: string
  replies: CommentRow[]
  user: CommentUser
}

const loaded = ref(false)
const mode = ref<'open' | 'review' | 'closed'>('open')
const total = ref(0)
const page = ref(1)
const perPage = 20
const items = ref<CommentRow[]>([])
const submitting = ref(false)
const content = ref('')
const replyTarget = ref<CommentRow | null>(null)
const notice = ref('')
const errorNotice = ref('')

const totalFloors = computed(() => total.value)

async function load(reset = true) {
  if (reset) {
    page.value = 1
    items.value = []
  }
  try {
    const data = await request<{ mode: string; total: number; items: CommentRow[] }>(
      `/comments?articleType=${props.articleType}&slug=${encodeURIComponent(props.slug)}&page=${page.value}&perPage=${perPage}`,
    )
    mode.value = (data.mode as typeof mode.value) ?? 'open'
    total.value = data.total
    const rows = data.items ?? []
    items.value = reset ? rows : [...items.value, ...rows]
    loaded.value = true
  } catch {
    loaded.value = true
  }
}

async function submit() {
  const text = content.value.trim()
  errorNotice.value = ''
  if (!text) return
  if (text.length > 1000) {
    errorNotice.value = '评论最长 1000 字'
    return
  }
  submitting.value = true
  try {
    const result = await request<{ status: string }>('/comments', {
      method: 'POST',
      body: {
        articleType: props.articleType,
        slug: props.slug,
        content: text,
        parentId: replyTarget.value?.id ?? 0,
      },
    })
    content.value = ''
    replyTarget.value = null
    notice.value = result.status === 'pending' ? '评论已提交，管理员审核通过后展示。' : ''
    await load()
  } catch (err: any) {
    errorNotice.value = err?.data?.message || err?.message || '发表失败，请稍后再试'
  } finally {
    submitting.value = false
  }
}

async function remove(row: CommentRow) {
  if (!window.confirm('确定删除这条评论？')) return
  try {
    await request(`/comments/${row.id}`, { method: 'DELETE' })
    await load()
  } catch {
    /* 删除失败保持现状 */
  }
}

function startReply(row: CommentRow) {
  replyTarget.value = row
  notice.value = ''
  errorNotice.value = ''
}

function cancelReply() {
  replyTarget.value = null
}

function loadMore() {
  page.value += 1
  load(false)
}

const canComment = computed(() => mode.value !== 'closed')
const modeLabel = computed(() => (mode.value === 'review' ? '本板块评论需审核后展示' : ''))

onMounted(async () => {
  if (isLoggedIn.value) await load()
})
</script>

<template>
  <section class="comment-section">
    <template v-if="isLoggedIn">
      <header class="comment-head">
        <h2><MessageSquare :size="16" />评论 <small>共 {{ totalFloors }} 楼</small></h2>
        <span v-if="modeLabel" class="comment-mode">{{ modeLabel }}</span>
      </header>

      <div class="comment-composer">
        <p v-if="replyTarget" class="reply-hint">
          回复 @{{ replyTarget.user.name }} 的 #{{ replyTarget.floor }}
          <button type="button" @click="cancelReply">取消回复</button>
        </p>
        <textarea
          v-model="content"
          rows="3"
          maxlength="1000"
          :placeholder="canComment ? '写下你的想法…' : '本文已关闭评论'"
          :disabled="!canComment"
        />
        <div class="composer-foot">
          <small>{{ content.length }}/1000</small>
          <button class="primary-button small" type="button" :disabled="!canComment || submitting || !content.trim()" @click="submit">
            <Send :size="13" />{{ replyTarget ? '发表回复' : '发表评论' }}
          </button>
        </div>
        <p v-if="notice" class="comment-notice">{{ notice }}</p>
        <p v-if="errorNotice" class="comment-error">{{ errorNotice }}</p>
      </div>

      <div v-if="!loaded" class="empty-state">评论加载中…</div>
      <div v-else-if="!items.length" class="empty-state">还没有评论，来抢沙发。</div>

      <ul v-else class="comment-list">
        <li v-for="row in items" :key="row.id" class="comment-card" :class="{ pinned: row.pinned }">
          <header class="comment-meta">
            <span class="comment-floor">#{{ row.floor }}</span>
            <span v-if="row.pinned" class="comment-pinned"><Pin :size="12" />置顶</span>
            <strong class="comment-name">{{ row.user.name }}</strong>
            <UserGroupBadge :group="row.user.userGroup" :role="row.user.role" />
            <time>{{ row.createdAt.slice(0, 16) }}</time>
            <span v-if="row.status === 'pending'" class="comment-pending">审核中</span>
            <span class="comment-actions">
              <button v-if="canComment" type="button" @click="startReply(row)">回复</button>
              <button v-if="row.isMine" type="button" class="danger" @click="remove(row)"><Trash2 :size="12" />删除</button>
            </span>
          </header>
          <p class="comment-content">{{ row.content }}</p>

          <ul v-if="row.replies.length" class="reply-list">
            <li v-for="reply in row.replies" :key="reply.id" class="reply-card">
              <header class="comment-meta">
                <strong class="comment-name">{{ reply.user.name }}</strong>
                <UserGroupBadge :group="reply.user.userGroup" :role="reply.user.role" />
                <span v-if="reply.replyTo" class="reply-to">回复 @{{ reply.replyTo }}</span>
                <time>{{ reply.createdAt.slice(0, 16) }}</time>
                <span v-if="reply.status === 'pending'" class="comment-pending">审核中</span>
                <span class="comment-actions">
                  <button v-if="canComment" type="button" @click="startReply(row)">回复</button>
                  <button v-if="reply.isMine" type="button" class="danger" @click="remove(reply)"><Trash2 :size="12" />删除</button>
                </span>
              </header>
              <p class="comment-content">{{ reply.content }}</p>
            </li>
          </ul>
        </li>
      </ul>

      <button v-if="loaded && items.length < total" class="ghost-button comment-more" type="button" @click="loadMore">
        加载更多（已显示 {{ items.length }}/{{ total }}）
      </button>
    </template>

    <div v-else class="comment-login-hint">
      <MessageSquare :size="16" />
      <p>登录后可查看和发表评论。</p>
      <NuxtLink class="primary-button small" to="/login">去登录</NuxtLink>
    </div>
  </section>
</template>

<style scoped>
.comment-section {
  margin-top: 2rem;
  padding: 1.25rem;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 8px;
}

.comment-head {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.comment-head h2 {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 1.0625rem;
  margin: 0;
}

.comment-head small {
  font-size: 0.8125rem;
  font-weight: 400;
  color: var(--text-muted);
}

.comment-mode {
  font-size: 0.8125rem;
  color: var(--accent, #b45309);
}

.comment-composer {
  margin-bottom: 1.25rem;
}

.comment-composer textarea {
  width: 100%;
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--bg, #fff);
  color: var(--text-primary);
  font-size: 0.875rem;
  resize: vertical;
}

.composer-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 0.375rem;
}

.composer-foot small {
  color: var(--text-muted);
}

.reply-hint {
  margin: 0 0 0.375rem;
  font-size: 0.8125rem;
  color: var(--accent, #b45309);
}

.reply-hint button {
  margin-left: 0.5rem;
  background: none;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 0.8125rem;
  text-decoration: underline;
}

.comment-notice {
  margin: 0.5rem 0 0;
  font-size: 0.8125rem;
  color: var(--accent, #b45309);
}

.comment-error {
  margin: 0.5rem 0 0;
  font-size: 0.8125rem;
  color: var(--error, #dc2626);
}

.comment-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.comment-card {
  padding: 0.75rem 0;
  border-top: 1px solid var(--border);
}

.comment-card.pinned {
  background: var(--primary-light, #fef2f2);
  border-radius: 6px;
  padding: 0.75rem 0.625rem;
}

.comment-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  font-size: 0.8125rem;
  color: var(--text-muted);
}

.comment-floor {
  font-weight: 700;
  color: var(--text-primary);
}

.comment-name {
  color: var(--text-primary);
  font-size: 0.875rem;
}

.comment-pinned {
  display: inline-flex;
  align-items: center;
  gap: 0.125rem;
  color: var(--primary, #b91c1c);
  font-weight: 600;
}

.comment-pending {
  color: var(--accent, #b45309);
  font-size: 0.75rem;
  border: 1px dashed currentColor;
  border-radius: 999px;
  padding: 0 0.375rem;
}

.comment-actions {
  margin-left: auto;
  display: inline-flex;
  gap: 0.625rem;
}

.comment-actions button {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  font-size: 0.8125rem;
  color: var(--text-muted);
  display: inline-flex;
  align-items: center;
  gap: 0.125rem;
}

.comment-actions button:hover {
  color: var(--primary, #b91c1c);
}

.comment-actions button.danger:hover {
  color: var(--error, #dc2626);
}

.comment-content {
  margin: 0.5rem 0 0;
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 0.9375rem;
}

.reply-list {
  list-style: none;
  margin: 0.625rem 0 0;
  padding: 0 0 0 1.25rem;
  border-left: 2px solid var(--border);
}

.reply-card {
  padding: 0.5rem 0;
}

.reply-to {
  color: var(--text-muted);
  font-size: 0.75rem;
}

.comment-more {
  margin-top: 0.875rem;
  width: 100%;
}

.comment-login-hint {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  color: var(--text-muted);
}

.comment-login-hint p {
  margin: 0;
}
</style>

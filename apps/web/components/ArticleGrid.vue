<script setup lang="ts">
import { ChevronRight, Lock, RotateCw } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'
import { groupBy, truncate } from '~/utils/articles.mjs'

const props = withDefaults(defineProps<{
  items: ArticleSummary[]
  basePath: string
  groupKey?: string
  showPriority?: boolean
  pending?: boolean
  failed?: boolean
}>(), {
  groupKey: '',
  showPriority: true,
})
const emit = defineEmits<{ retry: [] }>()

const gateOpen = ref(false)
const gateRedirect = ref('')
const route = useRoute()

function openGate() {
  gateRedirect.value = route.fullPath
  gateOpen.value = true
}

const groups = computed(() => {
  // 顺序完全以服务端返回为准：locked 是按该顺序计算出来的，
  // 前端再排序会让「免费 / 锁定」分界与提示错位。
  const base = props.groupKey ? groupBy(props.items, props.groupKey) : [{ name: '', items: props.items }]
  // 免费与锁定分开展示，避免「锁住的排在免费之前」造成顺序歧义
  return base.map((group) => ({
    name: group.name,
    free: group.items.filter((item) => !item.locked),
    locked: group.items.filter((item) => item.locked),
  }))
})

defineExpose({ gateOpen })
</script>

<template>
  <p v-if="pending" class="library-state" role="status">正在加载文章…</p>
  <div v-else-if="failed" class="library-state">
    <p role="alert">文章暂时加载失败。</p>
    <button class="ghost-button" type="button" @click="emit('retry')"><RotateCw :size="16" aria-hidden="true" />重试</button>
  </div>
  <p v-else-if="!items.length" class="library-state" role="status">暂无文章。</p>
  <div v-else class="article-groups">
    <section v-for="group in groups" :key="group.name" class="article-group">
      <div v-if="group.name" class="article-group-heading">
        <h2>{{ group.name }}</h2><span>{{ group.free.length + group.locked.length }} 篇</span>
      </div>
      <ul v-if="group.free.length" class="library-list">
        <li v-for="item in group.free" :key="item.slug">
        <NuxtLink
          :to="`${basePath}/${item.slug}`"
          class="article-row"
        >
          <span v-if="showPriority" class="priority-mark" :class="(item.priority || 'A').toLowerCase()">{{ item.priority || 'A' }}</span>
          <span class="article-copy">
            <strong>
              {{ item.title }}
              <span v-if="item.release" class="release-badge">发行版</span>
            </strong>
            <small>{{ truncate(item.summary, 110) }}</small>
            <em v-if="item.outline?.length">{{ item.outline.length }} 个章节</em>
          </span>
          <ChevronRight :size="17" aria-hidden="true" />
        </NuxtLink>
        </li>
      </ul>

      <template v-if="group.locked.length">
        <h3 class="locked-heading">登录后解锁 · {{ group.locked.length }} 篇</h3>
        <ul class="library-list">
          <li v-for="item in group.locked" :key="item.slug">
          <button
            type="button"
            class="article-row"
            aria-haspopup="dialog"
            @click="openGate"
          >
            <span v-if="showPriority" class="priority-mark" :class="(item.priority || 'A').toLowerCase()">{{ item.priority || 'A' }}</span>
            <span class="article-copy">
              <strong>
                {{ item.title }}
                <span class="lock-badge">需登录</span>
              </strong>
              <small>{{ truncate(item.summary, 110) }}</small>
            </span>
            <Lock :size="16" aria-hidden="true" />
          </button>
          </li>
        </ul>
      </template>
    </section>
  </div>
  <LoginGateModal :open="gateOpen" :redirect="gateRedirect" @close="gateOpen = false" />
</template>

<style scoped>
.article-group-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.article-group-heading h2, .locked-heading { margin: 0; overflow-wrap: anywhere; }
.article-group-heading h2 { font-size: 18px; font-weight: 650; }
.article-group-heading > span { flex: none; }
.article-group-heading > span, .locked-heading { color: var(--muted); font-size: 13px; }
.article-row { display: flex; align-items: flex-start; gap: 12px; width: 100%; min-height: 44px; padding: 14px 12px; border: 0; background: var(--gl-surface); text-align: start; }
.article-row .article-copy { flex: 1; overflow-wrap: anywhere; }
.article-row > svg { flex: none; align-self: center; color: var(--muted); }
.article-row:hover strong, .article-row:focus-visible strong { color: var(--brand); text-decoration: underline; }
</style>

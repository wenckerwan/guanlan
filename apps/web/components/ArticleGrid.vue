<script setup lang="ts">
import { Lock } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'
import { groupBy, splitByLock, truncate } from '~/utils/articles.mjs'

const props = withDefaults(defineProps<{
  items: ArticleSummary[]
  basePath: string
  groupKey?: string
  showPriority?: boolean
}>(), {
  groupKey: '',
  showPriority: true,
})

const gateOpen = ref(false)
const gateRedirect = ref('')

function openGate() {
  gateRedirect.value = useRoute().fullPath
  gateOpen.value = true
}

function onCardClick(item: ArticleSummary, event: MouseEvent) {
  if (!item.locked) return
  event.preventDefault()
  openGate()
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
  <div class="article-groups">
    <section v-for="group in groups" :key="group.name" class="article-group">
      <div v-if="group.name" class="section-heading compact">
        <div><span class="section-kicker">{{ group.name }}</span><h2>{{ group.free.length + group.locked.length }} 篇</h2></div>
      </div>
      <div class="article-grid">
        <NuxtLink
          v-for="item in group.free"
          :key="item.slug"
          :to="`${basePath}/${item.slug}`"
          class="article-card"
          :class="`tone-${(item.priority || 'A').toLowerCase()}`"
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
        </NuxtLink>
      </div>

      <template v-if="group.locked.length">
        <div class="section-heading compact gated-heading">
          <div><span class="section-kicker"><Lock :size="12" /> 登录后解锁</span><h2>还有 {{ group.locked.length }} 篇</h2></div>
        </div>
        <div class="article-grid">
          <a
            v-for="item in group.locked"
            :key="item.slug"
            href="#"
            class="article-card is-locked"
            :class="`tone-${(item.priority || 'A').toLowerCase()}`"
            @click="onCardClick(item, $event)"
          >
            <span v-if="showPriority" class="priority-mark" :class="(item.priority || 'A').toLowerCase()">{{ item.priority || 'A' }}</span>
            <span class="article-copy">
              <strong>
                {{ item.title }}
                <span class="lock-badge"><Lock :size="10" />需登录</span>
              </strong>
              <small>{{ truncate(item.summary, 110) }}</small>
            </span>
          </a>
        </div>
      </template>
    </section>
    <div v-if="!items.length" class="empty-state">暂无内容。</div>

    <LoginGateModal :open="gateOpen" :redirect="gateRedirect" @close="gateOpen = false" />
  </div>
</template>

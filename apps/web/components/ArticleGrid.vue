<script setup lang="ts">
import type { ArticleSummary } from '~/types/api'
import { groupBy, sortByPriority, truncate } from '~/utils/articles.mjs'

const props = withDefaults(defineProps<{
  items: ArticleSummary[]
  basePath: string
  groupKey?: string
  showPriority?: boolean
}>(), {
  groupKey: '',
  showPriority: true,
})

const groups = computed(() => {
  const sorted = props.showPriority ? sortByPriority(props.items) : props.items
  if (!props.groupKey) return [{ name: '', items: sorted }]
  return groupBy(sorted, props.groupKey)
})
</script>

<template>
  <div class="article-groups">
    <section v-for="group in groups" :key="group.name" class="article-group">
      <div v-if="group.name" class="section-heading compact">
        <div><span class="section-kicker">{{ group.name }}</span><h2>{{ group.items.length }} 篇</h2></div>
      </div>
      <div class="article-grid">
        <NuxtLink v-for="item in group.items" :key="item.slug" :to="`${basePath}/${item.slug}`" class="article-card" :class="`tone-${(item.priority || 'A').toLowerCase()}`">
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
    </section>
    <div v-if="!items.length" class="empty-state">暂无内容。</div>
  </div>
</template>
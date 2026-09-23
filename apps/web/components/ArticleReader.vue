<script setup lang="ts">
import { ref } from 'vue'
import type { ArticleDetail } from '~/types/api'

defineProps<{ article: ArticleDetail; backTo: string; backLabel: string }>()

const active = ref('')
</script>

<template>
  <div class="reader-layout">
    <aside v-if="article.outline?.length" class="reader-toc">
      <div class="section-heading compact"><div><span class="section-kicker">目录</span><h2>本篇结构</h2></div></div>
      <ul>
        <li v-for="(item, index) in article.outline" :key="index">
          <a :href="`#toc-${index}`" :class="{ active: active === `toc-${index}`, sub: item.level === 3 }" @click="active = `toc-${index}`">{{ item.title }}</a>
        </li>
      </ul>
    </aside>
    <article class="reader-body">
      <header class="reader-head">
        <span class="section-kicker">{{ article.priority || 'A' }} 级 · {{ article.category || article.layer || article.period || '资料' }}</span>
        <h1>{{ article.title }}</h1>
        <p v-if="article.summary">{{ article.summary }}</p>
      </header>
      <div class="markdown-body" v-html="article.html" />
      <NuxtLink class="back-link" :to="backTo">{{ backLabel }}</NuxtLink>
    </article>
  </div>
</template>
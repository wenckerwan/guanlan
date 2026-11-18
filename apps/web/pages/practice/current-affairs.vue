<script setup lang="ts">
import CurrentAffairsPractice from '~/components/practice/CurrentAffairsPractice.vue'

useHead({ title: '时政练习题｜观澜考研政治知识库', meta: [{ name: 'description', content: '2026 年时政专题原创练习题，按模块筛选并查看解析。' }] })
const study = usePracticeStudy()
onMounted(() => { void study.load() })
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs parent-name="时政热点" parent-path="/hotspots" subject-name="时政练习题" />
      <section v-if="study.loading.value || !study.bank.value" class="practice-page-state">
        <h1>时政练习题</h1>
        <div v-if="study.loading.value" class="library-state" role="status">正在加载时政题库…</div>
        <div v-else class="library-state">
          <p role="alert">{{ study.error.value || '暂时无法打开题库。' }}</p>
          <button class="ghost-button" type="button" @click="study.load">重新加载</button>
        </div>
      </section>
      <CurrentAffairsPractice v-else-if="study.bank.value" :key="`${study.identity.value || 'guest'}:${study.bank.value.contentVersion}`" :study="study" />
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.practice-page-state { margin-block: 24px 48px; }
.practice-page-state h1 { margin: 0; font-size: 32px; }
</style>

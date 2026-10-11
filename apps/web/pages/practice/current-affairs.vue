<script setup lang="ts">
useHead({ title: '时政练习题｜观澜考研政治知识库', meta: [{ name: 'description', content: '2026 年时政专题原创练习题，按模块筛选并查看解析。' }] })
const study = usePracticeStudy()
onMounted(() => { void study.load() })
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <div v-if="study.loading.value" class="empty-state" role="status">正在加载时政题库…</div>
      <section v-else-if="study.error.value && !study.bank.value" class="empty-state" role="alert">
        <h1>暂时无法打开题库</h1><p>{{ study.error.value }}</p>
        <button type="button" @click="study.load">重新加载</button>
      </section>
      <CurrentAffairsPractice v-else-if="study.bank.value" :key="`${study.identity.value || 'guest'}:${study.bank.value.contentVersion}`" :study="study" />
    </main>
    <SiteFooter />
  </div>
</template>

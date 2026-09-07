<script setup lang="ts">
defineProps<{
  subjectName?: string
}>()
</script>

<template>
  <nav class="breadcrumbs" aria-label="面包屑">
    <NuxtLink to="/">首页</NuxtLink><span>/</span><NuxtLink to="/subjects">资料库</NuxtLink><template v-if="subjectName"><span>/</span><strong>{{ subjectName }}</strong></template>
  </nav>
</template>

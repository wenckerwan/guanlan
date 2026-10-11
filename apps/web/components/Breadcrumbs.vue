<script setup lang="ts">
withDefaults(defineProps<{
  subjectName?: string
  parentName?: string
  parentPath?: string
}>(), { parentName: '资料库', parentPath: '/subjects' })
</script>

<template>
  <nav class="breadcrumbs" aria-label="面包屑">
    <NuxtLink to="/">首页</NuxtLink><span>/</span><NuxtLink :to="parentPath">{{ parentName }}</NuxtLink><template v-if="subjectName"><span>/</span><strong>{{ subjectName }}</strong></template>
  </nav>
</template>

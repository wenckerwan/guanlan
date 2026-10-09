<script setup lang="ts">
defineProps<{ loading: boolean; error: string; empty: boolean }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <p v-if="loading" class="admin-meta" role="status">正在加载，请稍候…</p>
  <div v-else-if="error" class="empty-state" role="alert">
    <p>{{ error }}</p>
    <button type="button" class="ghost-button" @click="$emit('retry')">重试</button>
  </div>
  <div v-else-if="empty" class="empty-state" role="status"><slot>没有匹配的记录。</slot></div>
</template>

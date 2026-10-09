<script setup lang="ts">
import { computed } from 'vue'
import { paginationState } from '~/utils/pagination.mjs'
const props = defineProps<{ page: number; perPage: number; total: number; busy?: boolean }>()
defineEmits<{ change: [page: number] }>()
const state = computed(() => paginationState(props.page, props.total, props.perPage))
</script>

<template>
  <nav class="admin-pagination" aria-label="列表分页">
    <span>{{ state.start }}–{{ state.end }} / {{ total }} 条</span>
    <button type="button" class="ghost-button small" :disabled="busy || !state.hasPrevious" @click="$emit('change', state.page - 1)">上一页</button>
    <span>第 {{ state.page }} / {{ state.pages }} 页</span>
    <button type="button" class="ghost-button small" :disabled="busy || !state.hasNext" @click="$emit('change', state.page + 1)">下一页</button>
  </nav>
</template>

<style scoped>
.admin-pagination { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 12px; color: var(--muted); font-size: 13px; }
</style>

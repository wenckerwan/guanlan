<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { pageWindow, paginationState } from '~/utils/pagination.mjs'

const props = withDefaults(defineProps<{
  page: number
  total: number
  perPage: number
}>(), {})

const emit = defineEmits<{ change: [page: number] }>()
const state = computed(() => paginationState(props.page, props.total, props.perPage))
const pages = computed(() => pageWindow(state.value.page, props.total, props.perPage))
</script>

<template>
  <nav v-if="total > perPage" class="pagination" aria-label="分页">
    <span class="pagination-summary">{{ state.start }}–{{ state.end }} / {{ total }}</span>
    <div class="pagination-actions">
      <button type="button" class="pagination-arrow" aria-label="上一页" :disabled="!state.hasPrevious" @click="emit('change', state.page - 1)">
        <ChevronLeft :size="16" />
      </button>
      <button
        v-for="number in pages"
        :key="number"
        type="button"
        class="pagination-number"
        :class="{ active: number === state.page }"
        :aria-current="number === state.page ? 'page' : undefined"
        :aria-label="`第 ${number} 页`"
        @click="emit('change', number)"
      >{{ number }}</button>
      <button type="button" class="pagination-arrow" aria-label="下一页" :disabled="!state.hasNext" @click="emit('change', state.page + 1)">
        <ChevronRight :size="16" />
      </button>
    </div>
  </nav>
</template>

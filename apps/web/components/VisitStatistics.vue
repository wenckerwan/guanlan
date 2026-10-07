<script setup lang="ts">
import { ChartNoAxesColumnIncreasing, CalendarDays, Info } from 'lucide-vue-next'

type VisitStats = { total: number; today: number; date: string; startedAt: string | null }
const stats = ref<VisitStats | null>(null)
function update(event: Event) {
  stats.value = (event as CustomEvent<VisitStats | null>).detail
}
const format = (value: number | undefined) => value === undefined ? '--' : value.toLocaleString('zh-CN')
onMounted(() => {
  window.addEventListener('guanlan:visits', update)
  stats.value = (window as Window & { GuanlanVisits?: VisitStats | null }).GuanlanVisits ?? null
})
onBeforeUnmount(() => window.removeEventListener('guanlan:visits', update))
</script>

<template>
  <div class="visit-statistics" aria-label="网站访问统计">
    <span><ChartNoAxesColumnIncreasing :size="14" aria-hidden="true" />累计访问 <strong>{{ format(stats?.total) }}</strong> 次</span>
    <span><CalendarDays :size="14" aria-hidden="true" />今日访问 <strong>{{ format(stats?.today) }}</strong> 次</span>
    <span class="visit-info" tabindex="0" aria-label="同一浏览器每小时最多计一次，今日按北京时间计算">
      <Info :size="14" aria-hidden="true" />
      <span role="tooltip">同一浏览器每小时最多计一次，今日按北京时间计算<span v-if="stats?.startedAt">；自 {{ stats.startedAt }} 起累计</span>。</span>
    </span>
  </div>
</template>

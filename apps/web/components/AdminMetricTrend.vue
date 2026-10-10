<script setup lang="ts">
const props = defineProps<{ title: string; unit: string; dates: string[]; values: Record<string, number> }>()
const series = computed(() => props.dates.map(day => ({ day, value: props.values[day] ?? 0 })))
const maximum = computed(() => Math.max(1, ...series.value.map(item => item.value)))
const labelEvery = computed(() => Math.max(1, Math.ceil(props.dates.length / 6)))
</script>

<template>
  <section class="metric-trend">
    <h3>{{ title }}</h3>
    <p class="admin-meta">纵轴范围：0 — {{ maximum }} {{ unit }}</p>
    <div class="chart" role="img" :aria-label="`${title}，共 ${dates.length} 日；完整数值见下方数据表`">
      <div v-for="(item, index) in series" :key="item.day" class="column">
        <div class="bar-space"><span class="bar" :style="{ height: `${item.value / maximum * 100}%` }" :title="`${item.day}：${item.value} ${unit}`" /></div>
        <small v-if="(index % labelEvery === 0 && index < dates.length - Math.ceil(labelEvery / 2)) || index === dates.length - 1" class="date-label">{{ item.day.slice(5) }}</small>
      </div>
    </div>
    <details>
      <summary>{{ title }}数据明细（{{ dates.length }} 日）</summary>
      <div class="table-scroll"><table class="admin-table"><caption>{{ title }}每日数值</caption><thead><tr><th scope="col">记录日期</th><th scope="col">{{ unit }}</th></tr></thead><tbody><tr v-for="item in series" :key="item.day"><th scope="row">{{ item.day }}</th><td>{{ item.value }}</td></tr></tbody></table></div>
    </details>
  </section>
</template>

<style scoped>
.metric-trend { min-width: 0; }
h3 { font-size: 1rem; }
.chart { display: flex; height: 160px; margin: 16px 20px 28px; border-bottom: 1px solid var(--border, #ddd); }
.column { flex: 1; min-width: 0; position: relative; }
.bar-space { height: 100%; display: flex; align-items: flex-end; }
.bar { display: block; width: 85%; background: var(--primary, #a83e38); border-radius: 2px 2px 0 0; }
.date-label { position: absolute; top: 100%; left: 50%; transform: translateX(-50%); padding-top: 5px; font-size: .65rem; white-space: nowrap; color: var(--text-muted); }
summary { cursor: pointer; padding: 8px 0; }
.table-scroll { overflow: auto; max-height: 340px; }
</style>

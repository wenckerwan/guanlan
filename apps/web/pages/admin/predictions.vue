<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { AdminListPayload, ArticleSummary } from '~/types/api'

const { request, restore } = useAuth()
restore()

const perPage = 20
const page = ref(1)
const total = ref(0)
const predictions = ref<ArticleSummary[]>([])
const message = ref('')

async function load() {
  try {
    const data = await request<AdminListPayload<ArticleSummary>>(`/admin/predictions?page=${page.value}&perPage=${perPage}`)
    predictions.value = data.items
    total.value = data.total
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '加载失败'
  }
}

onMounted(load)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

function go(next: number) {
  page.value = Math.min(Math.max(1, next), totalPages.value)
}

watch(page, load)
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">只读 · 数据集内容</span><h2>时政预测（{{ total }} 篇）</h2></div></div>
    <p class="admin-meta">预测文章由离线数据集导入，后台仅查看；要改内容请更新源数据并重新导入。</p>

    <table class="admin-table">
      <thead><tr><th>标题</th><th>层级</th><th>字数</th><th>来源文件</th></tr></thead>
      <tbody>
        <tr v-for="item in predictions" :key="item.slug">
          <td>{{ item.title }}</td>
          <td>{{ item.layer }}</td>
          <td>{{ item.wordCount }}</td>
          <td>{{ item.sourceFile }}</td>
        </tr>
      </tbody>
    </table>
    <div v-if="!predictions.length" class="empty-state">暂无预测内容。</div>
    <p v-if="message" class="admin-meta">{{ message }}</p>
    <div class="pagination-row">
      <button type="button" class="ghost-button small" :disabled="page <= 1" @click="go(page - 1)">上一页</button>
      <span class="admin-meta">第 {{ page }} / {{ totalPages }} 页</span>
      <button type="button" class="ghost-button small" :disabled="page >= totalPages" @click="go(page + 1)">下一页</button>
    </div>
  </section>
</template>

<style scoped>
.pagination-row {
  display: flex;
  gap: 1rem;
  align-items: center;
  justify-content: center;
  margin-top: 1rem;
}
</style>

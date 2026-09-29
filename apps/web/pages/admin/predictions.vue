<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { AdminListPayload, ArticleSummary } from '~/types/api'

type AdminPrediction = ArticleSummary & { id: number }

const { request, restore } = useAuth()
restore()

const perPage = 20
const page = ref(1)
const total = ref(0)
const predictions = ref<AdminPrediction[]>([])
const message = ref('')

async function load() {
  try {
    const data = await request<AdminListPayload<AdminPrediction>>(`/admin/predictions?page=${page.value}&perPage=${perPage}`)
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

async function toggleStatus(item: AdminPrediction) {
  const status = item.status === 'hidden' ? 'published' : 'hidden'
  message.value = ''
  try {
    await request(`/admin/predictions/${item.id}`, { method: 'PATCH', body: { status } })
    await load()
    message.value = status === 'hidden' ? '已隐藏' : '已发布'
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '操作失败'
  }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">时政预测</span><h2>共 {{ total }} 篇</h2></div></div>
    <p class="admin-meta">预测文章由离线数据集导入；支持发布 / 隐藏上下线管理。</p>

    <table class="admin-table">
      <thead><tr><th>标题</th><th>层级</th><th>字数</th><th>来源文件</th><th>状态</th></tr></thead>
      <tbody>
        <tr v-for="item in predictions" :key="item.slug">
          <td>{{ item.title }}</td>
          <td>{{ item.layer }}</td>
          <td>{{ item.wordCount }}</td>
          <td>{{ item.sourceFile }}</td>
          <td>
            <button type="button" class="ghost-button small" :class="{ hidden: item.status === 'hidden' }" @click="toggleStatus(item)">
              {{ item.status === 'hidden' ? '已隐藏' : '已发布' }}
            </button>
          </td>
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

.hidden { opacity: 0.5; }
</style>

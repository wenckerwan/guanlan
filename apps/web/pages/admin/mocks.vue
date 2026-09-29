<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { AdminListPayload, Mock } from '~/types/api'

const { request, restore } = useAuth()
restore()

const mocks = ref<Mock[]>([])
const total = ref(0)
const message = ref('')

onMounted(async () => {
  try {
    const data = await request<AdminListPayload<Mock>>('/admin/mocks?page=1&perPage=20')
    mocks.value = data.items
    total.value = data.total
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '加载失败'
  }
})
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">只读 · 数据集内容</span><h2>模拟押题（{{ total }} 套）</h2></div></div>
    <p class="admin-meta">模拟卷由离线数据集导入，后台仅查看；要改内容请更新源数据并重新导入。</p>

    <table class="admin-table">
      <thead><tr><th>标题</th><th>题数</th><th>满分</th><th>时长（分钟）</th></tr></thead>
      <tbody>
        <tr v-for="mock in mocks" :key="mock.slug">
          <td>{{ mock.title }}</td>
          <td>{{ mock.questionCount }}</td>
          <td>{{ mock.totalScore }}</td>
          <td>{{ mock.durationMinutes }}</td>
        </tr>
      </tbody>
    </table>
    <div v-if="!mocks.length" class="empty-state">暂无模拟卷。</div>
    <p v-if="message" class="admin-meta">{{ message }}</p>
  </section>
</template>

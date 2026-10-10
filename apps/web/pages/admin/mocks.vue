<script setup lang="ts">
import type { Mock } from '~/types/api'
const { items: mocks, total, filters, loading, loaded, loadError, visible, load, go } = useAdminList<Mock>('/admin/mocks')
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">只读 · 数据集内容</span><h2>模拟押题<span v-if="visible">（{{ total }} 套）</span></h2></div></div>
    <p class="admin-meta">模拟卷由离线数据集导入，后台仅查看；要改内容请更新源数据并重新导入。</p>

    <AdminListState :loading="loading" :error="loadError" :empty="loaded && !mocks.length" @retry="load">暂无模拟卷。</AdminListState>
    <table v-if="visible && mocks.length" class="admin-table">
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
    <AdminPagination v-if="visible" :page="filters.page" :per-page="filters.perPage" :total="total" :busy="loading" @change="go" />
  </section>
</template>

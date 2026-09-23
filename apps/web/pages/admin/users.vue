<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { User } from '~/types/api'

const { request, restore } = useAuth()
const items = ref<User[]>([])
const keyword = ref('')
const message = ref('')

async function load() {
  items.value = await request<User[]>(`/admin/users?q=${encodeURIComponent(keyword.value)}`)
}

onMounted(async () => {
  restore()
  await load()
})

async function update(user: User, patch: { role?: string; status?: string }) {
  const updated = await request<User>(`/admin/users/${user.id}`, { method: 'PATCH', body: patch })
  items.value = items.value.map((item) => (item.id === updated.id ? updated : item))
  message.value = `已更新 ${updated.email}`
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">用户管理</span><h2>共 {{ items.length }} 位用户</h2></div></div>

    <form class="filter-search" role="search" @submit.prevent="load">
      <input v-model="keyword" type="search" placeholder="按邮箱或昵称搜索" />
      <button class="ghost-button" type="submit">搜索</button>
    </form>

    <p v-if="message" class="admin-meta">{{ message }}</p>

    <table class="admin-table">
      <thead><tr><th>邮箱</th><th>昵称</th><th>角色</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="user in items" :key="user.id">
          <td>{{ user.email }}</td>
          <td>{{ user.displayName }}</td>
          <td>{{ user.role }}</td>
          <td>{{ user.status }}</td>
          <td class="admin-actions">
            <button type="button" class="ghost-button small" @click="update(user, { role: user.role === 'admin' ? 'user' : 'admin' })">{{ user.role === 'admin' ? '取消管理员' : '设为管理员' }}</button>
            <button type="button" class="ghost-button small" @click="update(user, { status: user.status === 'active' ? 'disabled' : 'active' })">{{ user.status === 'active' ? '禁用' : '启用' }}</button>
          </td>
        </tr>
      </tbody>
    </table>
    <div v-if="!items.length" class="empty-state">没有匹配的用户。</div>
  </section>
</template>
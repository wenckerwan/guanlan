<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { User, UserGroup } from '~/types/api'

const GROUP_OPTIONS: { value: UserGroup; label: string }[] = [
  { value: 'user', label: '普通用户' },
  { value: 'vip', label: 'VIP' },
  { value: 'svip', label: 'SVIP' },
  { value: 'sssvip', label: 'SSSVIP' },
]

const { request, restore } = useAuth()
const items = ref<User[]>([])
const keyword = ref('')
const message = ref('')
const draft = ref<Record<number, string>>({})

async function load() {
  items.value = await request<User[]>(`/admin/users?q=${encodeURIComponent(keyword.value)}`)
  draft.value = Object.fromEntries(items.value.map((user) => [user.id, user.mistakeCode ?? '']))
}

onMounted(async () => {
  restore()
  await load()
})

async function update(user: User, patch: { role?: string; status?: string; mistakeCode?: string; userGroup?: string }) {
  try {
    const updated = await request<User>(`/admin/users/${user.id}`, { method: 'PATCH', body: patch })
    items.value = items.value.map((item) => (item.id === updated.id ? updated : item))
    draft.value[updated.id] = updated.mistakeCode ?? ''
    message.value = `已更新 ${updated.email}`
  } catch (exception) {
    const data = (exception as { data?: { message?: string } })?.data
    message.value = data?.message || '更新失败'
  }
}

function changeGroup(user: User, event: Event) {
  const group = (event.target as HTMLSelectElement).value
  if (group && group !== user.userGroup) {
    void update(user, { userGroup: group })
  }
}

async function bind(user: User) {
  await update(user, { mistakeCode: (draft.value[user.id] ?? '').trim() })
}

const creating = ref(false)
const newDraft = ref({ email: '', password: '', displayName: '', role: 'user' })

async function createUser() {
  message.value = ''
  creating.value = true
  try {
    const created = await request<User>('/admin/users', { method: 'POST', body: { ...newDraft.value } })
    message.value = `已创建 ${created.email}`
    newDraft.value = { email: '', password: '', displayName: '', role: 'user' }
    await load()
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '创建失败'
  } finally {
    creating.value = false
  }
}

const resetTarget = ref<User | null>(null)
const resetPassword = ref('')

function openReset(user: User) {
  resetTarget.value = user
  resetPassword.value = ''
}

async function confirmReset() {
  if (!resetTarget.value) return
  if (resetPassword.value.length < 6) {
    message.value = '密码至少 6 位'
    return
  }
  message.value = ''
  try {
    await request(`/admin/users/${resetTarget.value.id}/reset-password`, {
      method: 'POST',
      body: { password: resetPassword.value },
    })
    message.value = `已重置 ${resetTarget.value.email} 的密码`
    resetTarget.value = null
    resetPassword.value = ''
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '重置失败'
  }
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

    <form class="admin-form" @submit.prevent="createUser">
      <label><span>新用户邮箱</span><input v-model="newDraft.email" type="email" required placeholder="user@example.com" /></label>
      <label><span>初始密码</span><input v-model="newDraft.password" type="text" required minlength="6" placeholder="至少 6 位" /></label>
      <label><span>昵称（可选）</span><input v-model="newDraft.displayName" type="text" /></label>
      <label><span>角色</span>
        <select v-model="newDraft.role"><option value="user">考生</option><option value="admin">管理员</option></select>
      </label>
      <button class="primary-button" type="submit" :disabled="creating">创建用户</button>
    </form>

    <table class="admin-table">
      <thead><tr><th>邮箱</th><th>昵称</th><th>角色</th><th>用户组</th><th>状态</th><th>账号 ID（绑定错题）</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="user in items" :key="user.id">
          <td>{{ user.email }}</td>
          <td>{{ user.displayName }}</td>
          <td>{{ user.role }}</td>
          <td>
            <select :value="user.userGroup || 'user'" aria-label="用户组" @change="changeGroup(user, $event)">
              <option v-for="option in GROUP_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </td>
          <td>{{ user.status }}</td>
          <td class="admin-bind">
            <input v-model="draft[user.id]" type="text" :placeholder="user.mistakeCode || '未绑定'" aria-label="账号 ID" />
            <button type="button" class="ghost-button small" @click="bind(user)">保存</button>
          </td>
          <td class="admin-actions">
            <button type="button" class="ghost-button small" @click="update(user, { role: user.role === 'admin' ? 'user' : 'admin' })">{{ user.role === 'admin' ? '取消管理员' : '设为管理员' }}</button>
            <button type="button" class="ghost-button small" @click="update(user, { status: user.status === 'active' ? 'disabled' : 'active' })">{{ user.status === 'active' ? '禁用' : '启用' }}</button>
            <button type="button" class="ghost-button small" @click="openReset(user)">重置密码</button>
          </td>
        </tr>
      </tbody>
    </table>
    <div v-if="!items.length" class="empty-state">没有匹配的用户。</div>

    <form v-if="resetTarget" class="admin-form" @submit.prevent="confirmReset">
      <p class="admin-meta">为 <strong>{{ resetTarget.email }}</strong> 设置新密码：</p>
      <label><span>新密码</span><input v-model="resetPassword" type="text" required minlength="6" placeholder="至少 6 位" /></label>
      <button class="primary-button" type="submit">确认重置</button>
      <button class="ghost-button" type="button" @click="resetTarget = null">取消</button>
    </form>
  </section>
</template>

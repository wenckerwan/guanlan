<script setup lang="ts">
definePageMeta({ layout: 'admin' })
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { userListFilters, userListQuery } from '~/utils/admin-users-query.mjs'
import type { AdminListPayload, User, UserGroup } from '~/types/api'

const GROUP_OPTIONS: { value: UserGroup; label: string }[] = [
  { value: 'user', label: '普通用户' },
  { value: 'vip', label: 'VIP' },
  { value: 'svip', label: 'SVIP' },
  { value: 'sssvip', label: 'SSSVIP' },
]

const { request, restore, user: currentUser, logout } = useAuth()
const route = useRoute()
const router = useRouter()
const filters = computed(() => userListFilters(route.query))
const items = ref<User[]>([])
const total = ref<number | null>(null)
const keyword = ref(filters.value.q)
const roleFilter = ref(filters.value.role)
const statusFilter = ref(filters.value.status)
const groupFilter = ref(filters.value.userGroup)
const pageSize = ref(filters.value.perPage)
const sizes = computed(() => [...new Set([20, 50, 100, pageSize.value])].sort((a, b) => a - b))
const message = ref('')
const loading = ref(false)
const loaded = ref(false)
const loadError = ref('')
const needsLogin = ref(false)
const updating = ref<number | null>(null)
const resetting = ref(false)
const draft = ref<Record<number, string>>({})
const busy = computed(() => loading.value || updating.value !== null || resetting.value || creating.value)
let sequence = 0
let started = false
let destroyed = false

function syncInputs() {
  keyword.value = filters.value.q
  roleFilter.value = filters.value.role
  statusFilter.value = filters.value.status
  groupFilter.value = filters.value.userGroup
  pageSize.value = filters.value.perPage
}

async function load() {
  const version = ++sequence
  const selected = { ...filters.value }
  loading.value = true
  loaded.value = false
  loadError.value = ''
  needsLogin.value = false
  try {
    const params = new URLSearchParams({ ...userListQuery(selected), page: String(selected.page), perPage: String(selected.perPage) })
    const data = await request<AdminListPayload<User>>(`/admin/users?${params}`)
    if (version !== sequence) return
    if (!Array.isArray(data.items) || !Number.isInteger(data.total) || data.total < 0 || !Number.isInteger(data.page) || !Number.isInteger(data.perPage)) throw new Error('Invalid list response')
    items.value = data.items
    total.value = data.total
    draft.value = Object.fromEntries(items.value.map((user) => [user.id, user.mistakeCode ?? '']))
    loaded.value = true
    if (data.page !== selected.page || data.perPage !== selected.perPage) {
      await router.replace({ query: userListQuery({ ...selected, page: data.page, perPage: data.perPage }) })
    }
  } catch (exception) {
    if (version !== sequence) return
    const error = exception as { status?: number; statusCode?: number }
    const status = error.status ?? error.statusCode
    needsLogin.value = status === 401
    loadError.value = status === 401 ? '登录已失效，请重新登录。' : status === 403 ? '当前账号没有后台权限。' : '用户列表加载失败，请重试。'
  } finally { if (version === sequence) loading.value = false }
}

async function select(next: ReturnType<typeof userListFilters>) {
  const query = userListQuery(next)
  if (JSON.stringify(query) === JSON.stringify(userListQuery(filters.value))) await load()
  else await router.push({ query })
}

async function search() {
  await select({ q: keyword.value.trim(), role: roleFilter.value, status: statusFilter.value, userGroup: groupFilter.value, page: 1, perPage: pageSize.value })
}

async function resetFilters() {
  await select({ q: '', role: '', status: '', userGroup: '', page: 1, perPage: 20 })
}

function go(page: number) { return select({ ...filters.value, page }) }

watch(() => route.query, () => {
  syncInputs()
  if (started) void load()
})
onMounted(async () => {
  await restore()
  if (destroyed) return
  started = true
  await load()
})
onBeforeUnmount(() => { destroyed = true; ++sequence; started = false })

async function update(user: User, patch: { role?: string; status?: string; mistakeCode?: string; userGroup?: string }) {
  if (busy.value) return
  if ((patch.role || patch.status) && !window.confirm(`确认修改 ${user.email} 的${patch.role ? '管理员权限' : '账号状态'}？`)) return
  updating.value = user.id
  try {
    const updated = await request<User>(`/admin/users/${user.id}`, { method: 'PATCH', body: patch })
    items.value = items.value.map((item) => (item.id === updated.id ? updated : item))
    draft.value[updated.id] = updated.mistakeCode ?? ''
    if (currentUser.value?.id === updated.id) {
      if (updated.status !== 'active') { await logout(); await navigateTo('/login'); return }
      currentUser.value = updated
      if (updated.role !== 'admin') { await navigateTo('/'); return }
    }
    message.value = `已更新 ${updated.email}`
    await load()
  } catch (exception) {
    const data = (exception as { data?: { message?: string } })?.data
    message.value = data?.message || '更新失败'
  } finally { updating.value = null }
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
  if (busy.value) return
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
  if (!resetTarget.value || busy.value) return
  if (resetPassword.value.length < 6) {
    message.value = '密码至少 6 位'
    return
  }
  message.value = ''
  resetting.value = true
  const targetId = resetTarget.value.id
  try {
    await request(`/admin/users/${targetId}/reset-password`, {
      method: 'POST',
      body: { password: resetPassword.value },
    })
    message.value = `已重置 ${resetTarget.value.email} 的密码，旧会话已退出`
    if (currentUser.value?.id === targetId) { await logout(); await navigateTo('/login'); return }
    resetTarget.value = null
    resetPassword.value = ''
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '重置失败'
  } finally { resetting.value = false; resetPassword.value = '' }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">用户管理</span><h2>用户管理<span v-if="loaded && !loading && !loadError">（共 {{ total }} 位用户）</span></h2></div></div>

    <form class="user-filters" role="search" @submit.prevent="search">
      <label class="keyword"><span>邮箱或昵称</span><input v-model="keyword" aria-label="邮箱或昵称" type="search" placeholder="按邮箱或昵称搜索" /></label>
      <label><span>角色筛选</span><select v-model="roleFilter" aria-label="角色筛选"><option value="">全部角色</option><option value="user">考生</option><option value="admin">管理员</option></select></label>
      <label><span>状态筛选</span><select v-model="statusFilter" aria-label="状态筛选"><option value="">全部状态</option><option value="active">启用</option><option value="disabled">禁用</option></select></label>
      <label><span>用户组筛选</span><select v-model="groupFilter" aria-label="用户组筛选"><option value="">全部用户组</option><option v-for="option in GROUP_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
      <label><span>每页条数</span><select v-model.number="pageSize" aria-label="每页条数"><option v-for="size in sizes" :key="size" :value="size">{{ size }} 条</option></select></label>
      <button class="primary-button" type="submit">筛选</button>
      <button class="ghost-button" type="button" @click="resetFilters">重置筛选</button>
    </form>

    <p v-if="message" class="admin-meta">{{ message }}</p>

    <form class="admin-form" @submit.prevent="createUser">
      <label><span>新用户邮箱</span><input v-model="newDraft.email" type="email" required placeholder="user@example.com" /></label>
      <label><span>初始密码</span><input v-model="newDraft.password" type="password" autocomplete="new-password" required minlength="6" placeholder="至少 6 位" /></label>
      <label><span>昵称（可选）</span><input v-model="newDraft.displayName" type="text" /></label>
      <label><span>角色</span>
        <select v-model="newDraft.role"><option value="user">考生</option><option value="admin">管理员</option></select>
      </label>
      <button class="primary-button" type="submit" :disabled="busy">创建用户</button>
    </form>

    <AdminListState :loading="loading" :error="loadError" :empty="loaded && !items.length" @retry="load">没有匹配的用户。</AdminListState>
    <NuxtLink v-if="needsLogin" class="primary-link" to="/login?redirect=/admin/users">重新登录</NuxtLink>
    <div v-if="loaded && !loading && !loadError && items.length" class="user-table-wrap">
    <table class="admin-table">
      <thead><tr><th>邮箱</th><th>昵称</th><th>角色</th><th>用户组</th><th>状态</th><th>账号 ID（绑定错题）</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="user in items" :key="user.id">
          <td>{{ user.email }}</td>
          <td>{{ user.displayName }}</td>
          <td>{{ user.role }}</td>
          <td>
            <select :value="user.userGroup || 'user'" :disabled="busy" aria-label="用户组" @change="changeGroup(user, $event)">
              <option v-for="option in GROUP_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </td>
          <td>{{ user.status }}</td>
          <td class="admin-bind">
            <input v-model="draft[user.id]" type="text" :placeholder="user.mistakeCode || '未绑定'" aria-label="账号 ID" />
            <button type="button" class="ghost-button small" :disabled="busy" @click="bind(user)">保存</button>
          </td>
          <td class="admin-actions">
            <button type="button" class="ghost-button small" :disabled="busy" @click="update(user, { role: user.role === 'admin' ? 'user' : 'admin' })">{{ user.role === 'admin' ? '取消管理员' : '设为管理员' }}</button>
            <button type="button" class="ghost-button small" :disabled="busy" @click="update(user, { status: user.status === 'active' ? 'disabled' : 'active' })">{{ user.status === 'active' ? '禁用' : '启用' }}</button>
            <button type="button" class="ghost-button small" :disabled="busy" @click="openReset(user)">重置密码</button>
          </td>
        </tr>
      </tbody>
    </table>
    </div>
    <AdminPagination v-if="loaded && !loading && !loadError && total !== null" :page="filters.page" :per-page="filters.perPage" :total="total" :busy="busy" @change="go" />

    <form v-if="resetTarget" class="admin-form" @submit.prevent="confirmReset">
      <p class="admin-meta">为 <strong>{{ resetTarget.email }}</strong> 设置新密码：</p>
      <label><span>新密码</span><input v-model="resetPassword" type="password" autocomplete="new-password" required minlength="6" placeholder="至少 6 位" /></label>
      <button class="primary-button" type="submit" :disabled="resetting">确认重置</button>
      <button class="ghost-button" type="button" :disabled="resetting" @click="resetTarget = null">取消</button>
    </form>
  </section>
</template>

<style scoped>
.user-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; padding: 16px; background: #fff; border: 1px solid var(--line); border-radius: 8px; }
.user-filters label { display: grid; gap: 6px; min-width: 110px; font-size: 12px; color: var(--muted); }
.user-filters .keyword { flex: 1; min-width: 220px; }
.user-filters input, .user-filters select { height: 38px; border: 1px solid var(--line); border-radius: 6px; padding: 0 10px; background: #fff; color: var(--ink); }
.user-filters input:focus-visible, .user-filters select:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
.user-table-wrap { overflow-x: auto; }
.user-table-wrap .admin-table { min-width: 840px; }
@media (max-width: 640px) { .user-filters label { flex: 1; } }
</style>

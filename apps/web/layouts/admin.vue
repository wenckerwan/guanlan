<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
const route = useRoute()
const { request, restore, token, user, invalidateToken } = useAuth()
const checking = ref(true), verified = ref(false), denied = ref(false), loginRequired = ref(false), failure = ref('')
const loginLink = computed(() => ({ path: '/login', query: { redirect: route.fullPath } }))
let generation = 0, alive = true
const nav = [
  ['/admin/overview', '总览'], ['/admin/users', '用户'], ['/admin/hotspots', '时政'], ['/admin/analysis', '分析'],
  ['/admin/papers', '真题'], ['/admin/mocks', '押题'], ['/admin/predictions', '预测'], ['/admin/mistakes', '错题'],
  ['/admin/comments', '评论'], ['/admin/audit-logs', '审计日志'],
]
async function verify() {
  const current = ++generation
  checking.value = true; verified.value = false; denied.value = false; loginRequired.value = false; failure.value = ''
  await restore()
  if (current !== generation || !alive) return
  if (!token.value) { loginRequired.value = true; checking.value = false; return }
  const expectedToken = token.value
  try {
    const result = await request<{ user: typeof user.value }>('/auth/me')
    if (current !== generation || !alive || token.value !== expectedToken) return
    if (!result.user || !Number.isInteger(result.user.id) || result.user.id < 1) throw new Error('Invalid identity')
    user.value = result.user
    try { localStorage.setItem('guanlan.user', JSON.stringify(result.user)) } catch {}
    verified.value = result.user.role === 'admin' && result.user.status === 'active'
    denied.value = !verified.value
  } catch (exception) {
    if (current !== generation || !alive || token.value !== expectedToken) return
    const error = exception as { status?: number; statusCode?: number }
    const status = error.status ?? error.statusCode
    if (status === 401) { invalidateToken(expectedToken); loginRequired.value = true }
    else if (status === 403) denied.value = true
    else failure.value = '后台身份验证暂时失败，请重试。'
  } finally { if (current === generation && alive) checking.value = false }
}
onMounted(verify)
watch(() => route.path, verify)
watch(token, verify)
onBeforeUnmount(() => { alive = false; ++generation })
</script>

<template>
  <div class="site-shell">
    <ClientOnly><SiteHeader /><template #fallback><header class="topbar"><div class="page-wrap admin-header-placeholder">观澜 · 管理后台</div></header></template></ClientOnly>
    <main class="page-wrap inner-page admin-page">
      <p v-if="checking" class="admin-meta" role="status">正在核对后台权限…</p>
      <section v-else-if="loginRequired" class="not-found-card"><h1>需要登录</h1><p>请用管理员账号登录后访问后台。</p><NuxtLink class="primary-link" :to="loginLink">前往登录</NuxtLink></section>
      <section v-else-if="denied" class="not-found-card"><h1>没有后台权限</h1><p>当前账号不能访问管理后台。</p><NuxtLink class="primary-link" to="/">返回首页</NuxtLink></section>
      <section v-else-if="failure" class="not-found-card" role="alert"><p>{{ failure }}</p><button class="ghost-button" type="button" @click="verify">重试身份验证</button></section>
      <template v-else-if="verified">
        <div class="admin-toolbar"><nav class="admin-nav" aria-label="管理后台导航"><NuxtLink v-for="item in nav" :key="item[0]" :to="item[0]">{{ item[1] }}</NuxtLink></nav><NuxtLink class="admin-return" to="/">返回站点</NuxtLink></div>
        <slot />
      </template>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.admin-header-placeholder { min-height: 76px; display: flex; align-items: center; color: var(--brand); font-weight: 700; }
.admin-toolbar { display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.admin-nav { flex: 1; min-width: 0; }
.admin-nav .router-link-exact-active { border-color: var(--brand); color: var(--brand); background: var(--brand-soft); }
.admin-return { padding: 8px 12px; border: 1px solid var(--line); border-radius: 6px; background: white; font-size: 13px; }
.admin-page { min-height: 55vh; }
.admin-page :deep(.admin-table) { width: 100%; }
.admin-page :deep(pre) { white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 640px) { .admin-header-placeholder { min-height: 76px; display: flex; align-items: center; color: var(--brand); font-weight: 700; }
.admin-toolbar { gap: 6px; } .admin-nav { flex-basis: 100%; } .admin-return { margin-bottom: 18px; } }
</style>

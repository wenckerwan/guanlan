<script setup lang="ts">
import { onMounted } from 'vue'
import { BarChart3, FileText, Users, ScrollText, Target, Lightbulb, ClipboardList, History, ArrowLeft, MessageSquare } from 'lucide-vue-next'

const router = useRouter()
const { user, isAdmin, restore, token } = useAuth()

onMounted(() => {
  restore()
})

const nav = [
  { to: '/admin', label: '总览', icon: BarChart3 },
  { to: '/admin/users', label: '用户', icon: Users },
  { to: '/admin/analysis', label: '分析', icon: FileText },
  { to: '/admin/papers', label: '真题', icon: ScrollText },
  { to: '/admin/mocks', label: '押题', icon: Target },
  { to: '/admin/predictions', label: '预测', icon: Lightbulb },
  { to: '/admin/mistakes', label: '错题', icon: ClipboardList },
  { to: '/admin/comments', label: '评论', icon: MessageSquare },
  { to: '/admin/audit-logs', label: '审计日志', icon: History },
]
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page admin-page">
      <div v-if="!token" class="not-found-card">
        <h1>需要登录</h1>
        <p>请先用管理员账号登录。</p>
        <NuxtLink class="primary-link" to="/login?redirect=/admin">前往登录</NuxtLink>
      </div>
      <div v-else-if="!isAdmin" class="not-found-card">
        <h1>没有后台权限</h1>
        <p>当前账号不是管理员。</p>
        <NuxtLink class="primary-link" to="/">返回首页</NuxtLink>
      </div>
      <template v-else>
        <div class="admin-topbar">
          <nav class="admin-nav">
            <NuxtLink v-for="item in nav" :key="item.to" :to="item.to"><component :is="item.icon" :size="15" />{{ item.label }}</NuxtLink>
          </nav>
          <NuxtLink class="admin-back" to="/"><ArrowLeft :size="15" />返回站点</NuxtLink>
        </div>
        <NuxtPage />
      </template>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.admin-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.admin-back {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.35rem 0.8rem;
  border: 1px solid var(--line, #e5e1d8);
  border-radius: 999px;
  font-size: 0.8125rem;
  color: var(--text-muted, #6b7280);
  background: #fff;
  white-space: nowrap;
}

.admin-back:hover {
  color: var(--red, #b91c1c);
  border-color: var(--red, #b91c1c);
}
</style>
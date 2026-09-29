<script setup lang="ts">
import { onMounted } from 'vue'
import { BarChart3, FileText, Newspaper, Users, ScrollText, Target, Lightbulb } from 'lucide-vue-next'

const router = useRouter()
const { user, isAdmin, restore, token } = useAuth()

onMounted(() => {
  restore()
})

const nav = [
  { to: '/admin', label: '总览', icon: BarChart3 },
  { to: '/admin/users', label: '用户', icon: Users },
  { to: '/admin/hotspots', label: '时政', icon: Newspaper },
  { to: '/admin/analysis', label: '分析', icon: FileText },
  { to: '/admin/papers', label: '真题', icon: ScrollText },
  { to: '/admin/mocks', label: '押题', icon: Target },
  { to: '/admin/predictions', label: '预测', icon: Lightbulb },
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
        <nav class="admin-nav">
          <NuxtLink v-for="item in nav" :key="item.to" :to="item.to"><component :is="item.icon" :size="15" />{{ item.label }}</NuxtLink>
        </nav>
        <NuxtPage />
      </template>
    </main>
  </div>
</template>
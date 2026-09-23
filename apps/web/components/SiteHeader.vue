<script setup lang="ts">
import { Bookmark, BookOpen, ClipboardList, Clock3, FileText, Menu, Newspaper, Search, Sparkles, TrendingUp, UserRound, X } from 'lucide-vue-next'

const isOpen = ref(false)
const route = useRoute()
const router = useRouter()
const { user, isLoggedIn, isAdmin, restore, logout } = useAuth()

const query = ref('')

const navItems = [
  { label: '首页', to: '/' },
  { label: '真题', to: '/papers' },
  { label: '时政', to: '/hotspots' },
  { label: '预测', to: '/predictions' },
  { label: '分析', to: '/analysis' },
  { label: '错题', to: '/mistakes' },
  { label: '模拟', to: '/mocks' },
  { label: '资料库', to: '/subjects' },
]

const drawerItems = [
  { label: '首页', to: '/', icon: BookOpen },
  { label: '真题回顾', to: '/papers', icon: FileText },
  { label: '时政热点', to: '/hotspots', icon: Newspaper },
  { label: '时政预测', to: '/predictions', icon: TrendingUp },
  { label: '真题分析', to: '/analysis', icon: Sparkles },
  { label: '错题分析', to: '/mistakes', icon: ClipboardList },
  { label: '模拟押题', to: '/mocks', icon: ClipboardList },
  { label: '资料库', to: '/subjects', icon: BookOpen },
]

const isActive = (to: string) => (to === '/' ? route.path === '/' : route.path.startsWith(to))

function closeMenu() {
  isOpen.value = false
}

function submitSearch() {
  const keyword = query.value.trim()
  if (!keyword) return
  router.push({ path: '/search', query: { q: keyword } })
}

onMounted(() => {
  restore()
})

async function handleLogout() {
  await logout()
  closeMenu()
  router.push('/')
}
</script>

<template>
  <header class="topbar">
    <div class="topbar-inner">
      <NuxtLink class="brand" to="/" aria-label="观澜首页" @click="closeMenu">
        <span class="brand-mark">观</span>
        <span><strong>观澜</strong><small>考研政治知识库</small></span>
      </NuxtLink>

      <nav class="desktop-nav" aria-label="主导航">
        <NuxtLink v-for="item in navItems" :key="item.to" :to="item.to" :class="{ active: isActive(item.to) }">{{ item.label }}</NuxtLink>
      </nav>

      <div class="topbar-actions">
        <form class="topbar-search" role="search" @submit.prevent="submitSearch">
          <Search :size="14" aria-hidden="true" />
          <input v-model="query" type="search" aria-label="站内搜索" placeholder="搜索" />
        </form>
        <NuxtLink v-if="isLoggedIn" class="text-action" to="/me"><UserRound :size="16" />{{ user?.displayName || '我的' }}</NuxtLink>
        <NuxtLink v-if="isLoggedIn" class="text-action" to="/me/favorites"><Bookmark :size="16" />收藏</NuxtLink>
        <NuxtLink v-if="isLoggedIn" class="text-action" to="/me/progress"><Clock3 :size="16" />记录</NuxtLink>
        <NuxtLink v-if="isAdmin" class="text-action" to="/admin">后台</NuxtLink>
        <NuxtLink v-if="!isLoggedIn" class="text-action auth-cta" to="/login">登录</NuxtLink>
        <button v-else class="text-action" type="button" @click="handleLogout">退出</button>
        <NuxtLink v-if="isLoggedIn" class="avatar" to="/me" aria-label="个人中心">{{ (user?.displayName || '我').slice(0, 1) }}</NuxtLink>
      </div>

      <button class="mobile-menu" type="button" aria-label="打开菜单" :aria-expanded="isOpen" @click="isOpen = true"><Menu :size="21" /></button>
    </div>
  </header>

  <div v-if="isOpen" class="mobile-drawer-layer" @click.self="closeMenu">
    <aside class="mobile-drawer" aria-label="移动端导航">
      <div class="mobile-drawer-head"><strong>资料导航</strong><button type="button" aria-label="关闭菜单" @click="closeMenu"><X :size="20" /></button></div>
      <form class="drawer-search" role="search" @submit.prevent="submitSearch(); closeMenu()">
        <Search :size="15" />
        <input v-model="query" type="search" aria-label="站内搜索" placeholder="搜索知识点、会议、年份" />
      </form>
      <nav class="mobile-drawer-nav">
        <NuxtLink v-for="item in drawerItems" :key="item.to" :to="item.to" :class="{ active: isActive(item.to) }" @click="closeMenu"><component :is="item.icon" :size="17" />{{ item.label }}</NuxtLink>
      </nav>
      <div class="drawer-account">
        <NuxtLink v-if="!isLoggedIn" to="/login" @click="closeMenu">登录 / 注册</NuxtLink>
        <template v-else>
          <NuxtLink to="/me" @click="closeMenu">个人中心</NuxtLink>
          <NuxtLink v-if="isAdmin" to="/admin" @click="closeMenu">管理后台</NuxtLink>
          <button type="button" @click="handleLogout">退出登录</button>
        </template>
      </div>
      <p class="drawer-note">V0.1-dev.3 · 真题 / 时政 / 错题 / 模拟</p>
    </aside>
  </div>

  <nav class="mobile-bottom-nav" aria-label="移动端主导航">
    <NuxtLink to="/" :class="{ active: route.path === '/' }"><BookOpen :size="18" />首页</NuxtLink>
    <NuxtLink to="/papers" :class="{ active: route.path.startsWith('/papers') }"><FileText :size="18" />真题</NuxtLink>
    <NuxtLink to="/hotspots" :class="{ active: route.path.startsWith('/hotspots') }"><Newspaper :size="18" />时政</NuxtLink>
    <NuxtLink to="/mistakes" :class="{ active: route.path.startsWith('/mistakes') }"><ClipboardList :size="18" />错题</NuxtLink>
  </nav>
</template>

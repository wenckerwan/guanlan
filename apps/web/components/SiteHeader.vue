<script setup lang="ts">
import { Bookmark, BookOpen, Clock3, FileText, Menu, Search, X } from 'lucide-vue-next'

const isOpen = ref(false)
const route = useRoute()

const navItems = [
  { label: '首页', to: '/' },
  { label: '资料库', to: '/subjects' },
]

const isActive = (to: string) => to === '/' ? route.path === '/' : route.path.startsWith(to)

function closeMenu() {
  isOpen.value = false
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
        <span class="nav-coming">真题分析</span>
        <span class="nav-coming">专题</span>
      </nav>

      <div class="topbar-actions">
        <button class="text-action" type="button" title="即将开放"><Bookmark :size="16" />收藏</button>
        <button class="text-action" type="button" title="即将开放"><Clock3 :size="16" />阅读记录</button>
        <span class="avatar">林</span>
      </div>

      <button class="mobile-menu" type="button" aria-label="打开菜单" :aria-expanded="isOpen" @click="isOpen = true"><Menu :size="21" /></button>
    </div>
  </header>

  <div v-if="isOpen" class="mobile-drawer-layer" @click.self="closeMenu">
    <aside class="mobile-drawer" aria-label="移动端导航">
      <div class="mobile-drawer-head"><strong>资料导航</strong><button type="button" aria-label="关闭菜单" @click="closeMenu"><X :size="20" /></button></div>
      <nav class="mobile-drawer-nav">
        <NuxtLink v-for="item in navItems" :key="item.to" :to="item.to" :class="{ active: isActive(item.to) }" @click="closeMenu"><BookOpen :size="17" />{{ item.label }}</NuxtLink>
        <span class="drawer-coming"><FileText :size="17" />真题分析 <small>即将开放</small></span>
        <span class="drawer-coming"><Search :size="17" />搜索 <small>即将开放</small></span>
      </nav>
      <p class="drawer-note">V0.1-dev.2 · 内容 API 接入 MySQL</p>
    </aside>
  </div>

  <nav class="mobile-bottom-nav" aria-label="移动端主导航">
    <NuxtLink to="/" :class="{ active: route.path === '/' }"><BookOpen :size="18" />首页</NuxtLink>
    <NuxtLink to="/subjects" :class="{ active: route.path.startsWith('/subjects') }"><FileText :size="18" />资料</NuxtLink>
    <button type="button" title="即将开放"><Search :size="18" />搜索</button>
    <button type="button" title="即将开放"><Bookmark :size="18" />我的</button>
  </nav>
</template>

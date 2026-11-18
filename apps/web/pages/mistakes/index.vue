<script setup lang="ts">
import { ArrowLeft, ChevronRight, ClipboardList, Info, RotateCw } from 'lucide-vue-next'
import type { MistakeStudent } from '~/types/api'

useHead({ title: '错题分析｜观澜考研政治知识库', meta: [{ name: 'description', content: '按考生隔离的错题分析与提分手册，含错因统计与重做清单。' }] })

const route = useRoute()
const router = useRouter()
const { isLoggedIn, restore } = useAuth()
if (import.meta.client) restore()

const { data, pending, error, refresh } = await useApiFetch<MistakeStudent[]>('/mistakes/students', [])
const students = computed(() => data.value ?? [])
const gateOpen = ref(false)

function topErrors(record: Record<string, number>) {
  return Object.entries(record).sort((a, b) => b[1] - a[1]).slice(0, 3)
}

function openGate() {
  gateOpen.value = true
}

onMounted(() => {
  // 私有错题本被 403 拦回时带 login=1，这里提示登录
  if (String(route.query.login ?? '') === '1') {
    gateOpen.value = true
    const query = { ...route.query }
    delete query.login
    router.replace({ query })
  }
})
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs />

      <section class="library-heading">
        <h1>错题分析</h1>
        <p>查看错因、重练错题，追踪复习进度。错题本仅本人和管理员可见。</p>
      </section>

      <div class="notice-banner" role="note">
        <Info :size="16" aria-hidden="true" />
        <span>错题分析服务会消耗 token，暂不支持免费分析，有需要可以联系管理员 QQ 206405650</span>
      </div>

      <p v-if="pending" class="library-state" role="status">正在加载错题本…</p>
      <div v-else-if="error" class="library-state">
        <p role="alert">错题本暂时加载失败。</p>
        <button class="ghost-button" type="button" @click="refresh()"><RotateCw :size="16" aria-hidden="true" />重试</button>
      </div>
      <section v-else-if="students.length" aria-labelledby="mistake-books">
        <div class="section-heading"><h2 id="mistake-books">错题本</h2><span class="catalog-count">{{ students.length }} 份</span></div>
        <ul class="library-list">
          <li v-for="student in students" :key="student.code">
            <NuxtLink :to="`/mistakes/${student.code}`" class="library-row">
              <span class="library-icon"><ClipboardList :size="18" aria-hidden="true" /></span>
              <div class="library-copy">
                <strong>{{ student.name }}</strong>
                <span>考生 {{ student.code }}<template v-if="student.relation"> · {{ student.relation }}</template> · {{ student.itemCount }} 道错题</span>
                <ul v-if="Object.keys(student.moduleCounts).length" class="student-modules">
                  <li v-for="[name, count] in Object.entries(student.moduleCounts)" :key="name">{{ name }} <b>{{ count }}</b></li>
                </ul>
                <ul v-if="Object.keys(student.errorTypes).length" class="student-errors">
                  <li v-for="[name, count] in topErrors(student.errorTypes)" :key="name">{{ name }} · {{ count }}</li>
                </ul>
              </div>
              <ChevronRight :size="17" aria-hidden="true" />
            </NuxtLink>
          </li>
        </ul>
      </section>

      <p v-else-if="isLoggedIn" class="library-state" role="status">你的专属错题本正在准备中。如有问题请联系管理员。</p>
      <section v-else class="empty-prompt">
        <h2>开始使用错题本</h2>
        <p>登录或注册后，系统会自动为你创建专属错题本，记录你的错题和复习进度。</p>
        <button class="primary-button" type="button" @click="openGate">登录查看我的错题本</button>
      </section>

      <NuxtLink class="back-link" to="/"><ArrowLeft :size="15" aria-hidden="true" />返回首页</NuxtLink>

      <LoginGateModal
        :open="gateOpen"
        title="登录查看你的错题本"
        message="登录后系统会自动创建你的专属错题本，记录错题、分析错因、追踪复习进度。数据完全私密，仅你和管理员可见。"
        @close="gateOpen = false"
      />
    </main>
    <SiteFooter />
  </div>
</template>

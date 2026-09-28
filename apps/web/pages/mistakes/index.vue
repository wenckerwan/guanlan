<script setup lang="ts">
import { ClipboardList, Info, KeyRound, ShieldCheck } from 'lucide-vue-next'
import type { MistakeStudent } from '~/types/api'

useHead({ title: '错题分析｜观澜考研政治知识库', meta: [{ name: 'description', content: '按考生隔离的错题分析与提分手册，含错因统计与重做清单。' }] })

const route = useRoute()
const router = useRouter()
const { data } = await useApiFetch<MistakeStudent[]>('/mistakes/students', [])
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
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <div class="notice-banner" role="note">
        <Info :size="16" />
        <span>错题分析服务会消耗 token，暂不支持免费分析，有需要可以联系管理员 QQ 206405650</span>
      </div>

      <section class="page-hero">
        <div class="eyebrow"><ClipboardList :size="14" />个人错题分析</div>
        <h1>不告诉你错了什么，<br class="mobile-only" />只告诉你下次怎么做</h1>
        <p>下面 {{ students.length }} 份错题本对当前身份可见。每份数据在考生之间完全隔离，只有本人账号与管理员能打开自己的那一份。</p>
      </section>

      <section class="student-grid">
        <NuxtLink v-for="student in students" :key="student.code" :to="`/mistakes/${student.code}`" class="student-card">
          <header>
            <span class="student-code">考生 {{ student.code }}</span>
            <strong>{{ student.name }}</strong>
            <small>{{ student.relation }}</small>
          </header>
          <div class="student-stat">
            <b>{{ student.itemCount }}</b><small>道错题</small>
          </div>
          <ul class="student-modules">
            <li v-for="[name, count] in Object.entries(student.moduleCounts)" :key="name">{{ name }} <b>{{ count }}</b></li>
          </ul>
          <ul class="student-errors">
            <li v-for="[name, count] in topErrors(student.errorTypes)" :key="name">{{ name }} · {{ count }}</li>
          </ul>
          <p class="student-note"><ShieldCheck :size="13" />数据在考生之间完全隔离</p>
        </NuxtLink>
      </section>
      <div v-if="!students.length" class="empty-state">错题数据暂不可用。</div>

      <section class="student-private">
        <h2>没有看到自己的错题本？</h2>
        <p>注册后系统会生成一个专属账号 ID，把它发给管理员完成绑定；绑定后只有你和管理员能打开，其他账号与游客都看不到。</p>
        <button class="ghost-button" type="button" @click="openGate"><KeyRound :size="14" />查看绑定说明</button>
      </section>

      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
      <LoginGateModal
        :open="gateOpen"
        title="登录查看自己的错题本"
        message="在个人中心可以看到系统分配的账号 ID，把它发给管理员绑定错题数据；绑定后仅你和管理员可见。"
        @close="gateOpen = false"
      />
    </main>
  </div>
</template>

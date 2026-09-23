<script setup lang="ts">
import { ref } from 'vue'
import { LogIn, UserPlus } from 'lucide-vue-next'

useHead({ title: '登录｜观澜考研政治知识库' })

const route = useRoute()
const router = useRouter()
const { login, register, restore } = useAuth()

const mode = ref<'login' | 'register'>(route.query.mode === 'register' ? 'register' : 'login')
const email = ref('')
const password = ref('')
const displayName = ref('')
const error = ref('')
const pending = ref(false)

onMounted(() => restore())

async function submit() {
  error.value = ''
  pending.value = true
  try {
    if (mode.value === 'register') {
      await register(email.value.trim(), password.value, displayName.value.trim())
    } else {
      await login(email.value.trim(), password.value)
    }
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/me'
    await router.push(redirect)
  } catch (exception) {
    const data = (exception as { data?: { message?: string } })?.data
    error.value = data?.message || '操作失败，请检查邮箱与密码'
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page auth-page">
      <section class="auth-card">
        <div class="auth-tabs">
          <button type="button" :class="{ active: mode === 'login' }" @click="mode = 'login'"><LogIn :size="15" />登录</button>
          <button type="button" :class="{ active: mode === 'register' }" @click="mode = 'register'"><UserPlus :size="15" />注册</button>
        </div>

        <h1>{{ mode === 'login' ? '欢迎回来' : '创建观澜账号' }}</h1>
        <p class="auth-copy">{{ mode === 'login' ? '登录后可收藏题目、记录做题进度。' : '注册即可保存错题本与做题记录。' }}</p>

        <form class="auth-form" @submit.prevent="submit">
          <label v-if="mode === 'register'">
            <span>昵称（可选）</span>
            <input v-model="displayName" type="text" autocomplete="nickname" placeholder="例如：小林" />
          </label>
          <label>
            <span>邮箱</span>
            <input v-model="email" type="email" required autocomplete="email" placeholder="you@example.com" />
          </label>
          <label>
            <span>密码</span>
            <input v-model="password" type="password" required :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" :minlength="mode === 'register' ? 6 : undefined" placeholder="至少 6 位" />
          </label>

          <p v-if="error" class="auth-error">{{ error }}</p>

          <button class="primary-button wide" type="submit" :disabled="pending">
            {{ pending ? '处理中…' : (mode === 'login' ? '登录' : '注册并登录') }}
          </button>
        </form>

        <p class="auth-foot">
          <template v-if="mode === 'login'">还没有账号？<button type="button" class="link-button" @click="mode = 'register'">立即注册</button></template>
          <template v-else>已有账号？<button type="button" class="link-button" @click="mode = 'login'">去登录</button></template>
        </p>
      </section>
    </main>
  </div>
</template>
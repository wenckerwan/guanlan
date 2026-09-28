<script setup lang="ts">
import { LockKeyhole, X } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  open: boolean
  redirect?: string
  title?: string
  message?: string
}>(), {
  redirect: '',
  title: '登录后查看完整内容',
  message: '游客每个栏目可免费阅读 3 篇，注册登录后不限额。',
})

const emit = defineEmits<{ close: [] }>()
const router = useRouter()

function goLogin() {
  const target = props.redirect || useRoute().fullPath
  emit('close')
  router.push({ path: '/login', query: { redirect: target } })
}

function onKey(event: KeyboardEvent) {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="gate-layer" role="dialog" aria-modal="true" aria-labelledby="gate-title" @click.self="emit('close')">
      <div class="gate-card">
        <button class="gate-close" type="button" aria-label="关闭" @click="emit('close')"><X :size="18" /></button>
        <span class="gate-icon"><LockKeyhole :size="20" /></span>
        <h2 id="gate-title">{{ title }}</h2>
        <p>{{ message }}</p>
        <div class="gate-actions">
          <button class="primary-button" type="button" @click="goLogin">去登录</button>
          <button class="ghost-button" type="button" @click="emit('close')">先看看别的</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

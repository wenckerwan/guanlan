<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { loadTurnstile, mountChallenge } from '~/utils/turnstile.mjs'

const props = defineProps<{ siteKey: string; resetKey: number }>()
const emit = defineEmits<{ token: [value: string] }>()
const container = ref<HTMLElement | null>(null)
const error = ref('')
let active = true
let widget: ReturnType<typeof mountChallenge> | undefined

async function initialize() {
  error.value = ''
  widget?.remove()
  widget = undefined
  try {
    const api = await loadTurnstile()
    if (!active || !container.value) return
    widget = mountChallenge(api, container.value, props.siteKey, (token: string) => emit('token', token), () => {
      error.value = '验证未完成，请点击重试'
    })
  } catch {
    if (active) error.value = '验证组件暂时无法加载，请检查网络后重试'
  }
}

watch(() => props.resetKey, () => widget?.reset())
onMounted(initialize)
onBeforeUnmount(() => { active = false; widget?.remove() })
</script>

<template>
  <div class="registration-challenge">
    <div ref="container" />
    <p v-if="error" role="alert" class="auth-error">
      {{ error }} <button type="button" class="link-button" @click="initialize">重试</button>
    </p>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRegisterSW } from 'virtual:pwa-register/vue'

// 版本更新策略：检测到新 Service Worker 时不静默接管，
// 由用户确认后刷新，避免打断做题/作答（M1-T3，见 观澜丨App/M1-PWA实施计划.md）。
const needRefresh = ref(false)
const updating = ref(false)

const updateServiceWorker = useRegisterSW({
  onNeedRefresh() {
    needRefresh.value = true
  },
})

function refresh() {
  updating.value = true
  updateServiceWorker(true)
}
</script>

<template>
  <div v-if="needRefresh" class="pwa-update-bar" role="status">
    <span class="pwa-update-text">观澜已发布新版本</span>
    <button class="pwa-update-button" type="button" :disabled="updating" @click="refresh">
      {{ updating ? '更新中…' : '刷新更新' }}
    </button>
  </div>
</template>

<style scoped>
.pwa-update-bar {
  position: fixed;
  right: 16px;
  bottom: 16px;
  z-index: 60;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border: 1px solid var(--line);
  background: #fff;
  box-shadow: 0 6px 18px rgba(36, 37, 34, 0.12);
}

.pwa-update-text {
  font-size: 12px;
  color: var(--ink);
}

.pwa-update-button {
  padding: 6px 12px;
  border: 1px solid var(--red);
  background: var(--red);
  color: #fff;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
}

.pwa-update-button:disabled {
  opacity: 0.55;
  cursor: default;
}
</style>

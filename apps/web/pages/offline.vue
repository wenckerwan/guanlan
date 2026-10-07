<script setup lang="ts">
import { WifiOff } from 'lucide-vue-next'

useHead({ title: '离线｜观澜考研政治知识库' })
</script>

<template>
  <div class="offline-shell">
    <div class="offline-card">
      <div class="offline-icon">
        <WifiOff :size="22" />
      </div>
      <h1>当前离线</h1>
      <p>网络连接不可用。已浏览过的真题、文章等内容仍可从底部导航或历史记录进入查看；做题记录、错题本与 AI 分析需要联网后使用。</p>
      <button class="primary-button" type="button" @click="reload">重新连接</button>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  methods: {
    reload() {
      window.location.reload()
    },
  },
}
</script>

<style scoped>
.offline-shell {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px;
}

.offline-card {
  max-width: 460px;
  padding: 44px 34px;
  border: 1px solid var(--line);
  background: #fff;
  text-align: center;
}

.offline-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  margin: 0 auto 18px;
  border-radius: 8px;
  color: var(--red);
  background: var(--red-soft);
}

.offline-card h1 {
  margin: 0;
  font-family: serif;
  font-size: 26px;
  font-weight: 500;
}

.offline-card p {
  margin: 12px 0 24px;
  color: var(--muted);
  font-size: 13px;
  line-height: 1.9;
  text-align: left;
}
</style>

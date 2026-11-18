<script setup lang="ts">
import { Monitor } from 'lucide-vue-next'
const { appearance } = useAppearance()
</script>

<template>
  <label class="appearance-control">
    <Monitor :size="15" aria-hidden="true" />
    <select v-model="appearance" aria-label="界面外观">
      <option value="system">跟随系统</option>
      <option value="light">浅色</option>
      <option value="dark">深色</option>
    </select>
  </label>
</template>

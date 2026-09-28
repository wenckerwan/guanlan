<script setup lang="ts">
// 服务端首帧解析 Cookie 登录态并拉取用户资料，
// 使 SSR 输出即为正确的登录视图，避免「先游客后登录」闪烁。
await useAuth().restore()
</script>

<template>
  <NuxtPage />
</template>

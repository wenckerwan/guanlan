<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  group?: string
  role?: string
}>()

const META: Record<string, { label: string; cls: string }> = {
  guest: { label: '未注册', cls: 'ug-guest' },
  user: { label: '普通用户', cls: 'ug-user' },
  vip: { label: 'VIP', cls: 'ug-vip' },
  svip: { label: 'SVIP', cls: 'ug-svip' },
  sssvip: { label: 'SSSVIP', cls: 'ug-sssvip' },
}

const meta = computed(() => {
  if (props.role === 'admin') return { label: '管理员', cls: 'ug-admin' }
  return META[props.group ?? 'guest'] ?? META.guest
})
</script>

<template>
  <span class="user-group-badge" :class="meta.cls">{{ meta.label }}</span>
</template>

<style scoped>
.user-group-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  font-size: 0.6875rem;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  border: 1px solid transparent;
}

.ug-guest {
  background: #f3f4f6;
  color: #6b7280;
  border-color: #e5e7eb;
}

.ug-user {
  background: #eff6ff;
  color: #2563eb;
  border-color: #bfdbfe;
}

.ug-vip {
  background: #f5f3ff;
  color: #7c3aed;
  border-color: #ddd6fe;
}

.ug-svip {
  background: #fffbeb;
  color: #b45309;
  border-color: #fde68a;
}

.ug-sssvip {
  background: linear-gradient(135deg, #fff1f2, #fef3c7);
  color: #b91c1c;
  border-color: #fecaca;
}

.ug-admin {
  background: #fee2e2;
  color: #b91c1c;
  border-color: #fecaca;
}
</style>

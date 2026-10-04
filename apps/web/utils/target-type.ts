/**
 * 收藏/笔记对象类型 → 中文名映射。
 * 覆盖观澜现有 9 类 + 马原 4 类 + 史纲 2 类。
 */
export const TARGET_TYPE_LABELS: Record<string, string> = {
  question: '题目',
  paper: '真题',
  analysis: '真题分析',
  prediction: '时政预测',
  mistake: '错题本',
  mistake_item: '错题',
  handbook: '手册',
  mock: '模拟押题',
  // 马原知识宇宙
  mayuan_concept: '马原·概念',
  mayuan_relation: '马原·关系',
  mayuan_comparison: '马原·辨析',
  mayuan_experiment: '马原·实验',
  // 近现代史时间实验室
  history_event: '史纲·事件',
  history_comparison: '史纲·对照',
}

export function targetTypeLabel(type: string): string {
  return TARGET_TYPE_LABELS[type] ?? type
}

/**
 * 子应用（/mayuan/、/history/）由 nginx 分流到独立前端，不能用 Nuxt 路由跳转，
 * 需整页跳转。其余站内 Nuxt 路由仍用 NuxtLink。
 */
export function isSubAppUrl(url: string): boolean {
  return url.startsWith('/mayuan/') || url.startsWith('/history/')
}

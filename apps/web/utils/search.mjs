/**
 * 站内搜索的纯函数工具：类型标签、结果计数与空态判定。
 * 与 UI 解耦，便于 `node --test` 直接覆盖。
 */

export const SEARCH_TYPES = [
  { value: 'question', label: '真题' },
  { value: 'paper', label: '试卷' },
  { value: 'analysis', label: '真题分析' },
  { value: 'hotspot', label: '时政热点' },
  { value: 'prediction', label: '时政预测' },
  { value: 'mock', label: '模拟押题' },
  { value: 'mistake', label: '错题' },
]

export const SEARCH_RESULT_FALLBACK = { items: [], total: 0, groups: {} }

/** 关键词归一：去首尾空白，压缩内部空白，最长 60 字（与后端限制一致）。 */
export function normalizeKeyword(raw) {
  const value = String(raw ?? '').replace(/\s+/g, ' ').trim()
  return value.slice(0, 60)
}

export function typeLabel(type) {
  return SEARCH_TYPES.find((item) => item.value === type)?.label ?? String(type ?? '')
}

/** 后端 `groups` 缺失或与 items 不一致时，以 items 现算的计数为准。 */
export function groupCounts(result) {
  const items = Array.isArray(result?.items) ? result.items : []
  const counts = {}
  for (const item of items) {
    if (!item?.type) continue
    counts[item.type] = (counts[item.type] ?? 0) + 1
  }
  return counts
}

export function isEmptyResult(result) {
  return !Array.isArray(result?.items) || result.items.length === 0
}

/**
 * 结果项路由：只接受站内相对路径（`/` 开头）。
 * 外部链接与空值一律回退到该条目的关键词检索，避免把用户带出站外。
 */
export function resolveHitUrl(item) {
  const url = String(item?.url ?? '').trim()
  if (url.startsWith('/') && !url.startsWith('//')) return url
  return `/search?q=${encodeURIComponent(normalizeKeyword(item?.title ?? ''))}`
}
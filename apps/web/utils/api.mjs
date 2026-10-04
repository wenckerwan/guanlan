import { buildHomeSummary } from './home.mjs'

/**
 * 统一拆包 API 包络 `{ data: ... }`。
 * 当 payload 为空、非对象或缺少 data 字段时返回安全默认值。
 */
export function unwrapEnvelope(payload, fallback = null) {
  if (payload && typeof payload === 'object' && 'data' in payload && payload.data != null) {
    return payload.data
  }
  return fallback
}

/**
 * 把 `/home` 的包络转成首屏摘要：
 * subjects 截断到 6 条，documents 原样透传。
 */
export function toHomeSummary(payload) {
  const data = unwrapEnvelope(payload, { documents: [], subjects: [] })
  const summary = buildHomeSummary({
    subjects: Array.isArray(data.subjects) ? data.subjects : [],
  })
  return {
    subjects: summary.subjects,
    documents: Array.isArray(data.documents) ? data.documents : [],
  }
}

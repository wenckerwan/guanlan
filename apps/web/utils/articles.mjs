/**
 * 文章类工具：目录高亮、摘要截断、优先级排序。
 */

const PRIORITY_WEIGHT = { S: 3, A: 2, B: 1, C: 0 }

export function sortByPriority(items, key = 'priority') {
  return [...items].sort((left, right) => {
    const delta = (PRIORITY_WEIGHT[right[key]] ?? 0) - (PRIORITY_WEIGHT[left[key]] ?? 0)
    if (delta !== 0) return delta
    // 同星级时「发行版」优先于工作稿，读者先看到定稿
    const releaseDelta = Number(Boolean(right.release)) - Number(Boolean(left.release))
    if (releaseDelta !== 0) return releaseDelta
    return String(right.period ?? right.slug ?? '').localeCompare(String(left.period ?? left.slug ?? ''))
  })
}

export function groupBy(items, key) {
  const groups = new Map()
  for (const item of items) {
    const value = String(item[key] ?? '其他')
    if (!groups.has(value)) groups.set(value, [])
    groups.get(value).push(item)
  }
  return [...groups.entries()].map(([name, list]) => ({ name, items: list }))
}

export function truncate(text, limit = 120) {
  const body = String(text ?? '').replace(/\s+/g, ' ').trim()
  return body.length <= limit ? body : `${body.slice(0, limit)}…`
}

/** 从 HTML 生成纯文本目录锚点（后端已给 outline，这里兜底）。 */
export function outlineOf(html, max = 30) {
  const matches = String(html ?? '').matchAll(/<h([23])[^>]*>(.*?)<\/h\1>/g)
  const items = []
  for (const match of matches) {
    if (items.length >= max) break
    items.push({
      level: Number(match[1]),
      title: match[2].replace(/<[^>]+>/g, '').trim(),
    })
  }
  return items
}

export function moduleTone(module) {
  const map = {
    马原: 'jade',
    毛中特: 'red',
    史纲: 'gold',
    思法: 'blue',
    习思想: 'violet',
    当代: 'orange',
  }
  return map[module] ?? 'jade'
}

/**
 * 把栏目条目按访客配额拆成「可阅读」与「需登录」两组。
 *
 * 顺序完全沿用服务端返回的顺序：locked 是后端按该顺序计算出来的，
 * 前端若重排会让免费 / 锁定分界与提示错位。
 */
export function splitByLock(items) {
  // 丢掉 null / 非对象项，避免脏数据渲染成空卡片
  const list = (Array.isArray(items) ? items : []).filter(
    (item) => item !== null && typeof item === 'object' && !Array.isArray(item),
  )
  return {
    free: list.filter((item) => !item.locked),
    locked: list.filter((item) => Boolean(item.locked)),
  }
}

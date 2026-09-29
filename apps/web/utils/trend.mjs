/**
 * 注册趋势纯函数：把后端按天分组的计数补齐成连续 N 天序列。
 * 与页面解耦，便于 node --test 直接覆盖。
 */

/** 最近 n 天的日期串（含今天），旧 → 新。now 可传 Date 或可被 Date 解析的值，便于测试。 */
export function lastNDays(now, n) {
  const end = now instanceof Date ? now : new Date(now)
  const days = []
  for (let offset = n - 1; offset >= 0; offset -= 1) {
    const day = new Date(end)
    day.setDate(day.getDate() - offset)
    days.push(localDateKey(day))
  }
  return days
}

function localDateKey(day) {
  const month = String(day.getMonth() + 1).padStart(2, '0')
  const date = String(day.getDate()).padStart(2, '0')
  return `${day.getFullYear()}-${month}-${date}`
}

/**
 * 合并后端 { 'YYYY-MM-DD': count } 到连续序列 [{ day, count }]。
 * 缺档日补 0；后端多出的日期（超出窗口）忽略。
 */
export function fillTrend(perDay, days) {
  const source = perDay && typeof perDay === 'object' ? perDay : {}
  return days.map((day) => ({ day, count: Number(source[day]) || 0 }))
}

/** 序列总和（总览卡片用）。 */
export function trendTotal(series) {
  return (Array.isArray(series) ? series : []).reduce((sum, item) => sum + (Number(item?.count) || 0), 0)
}

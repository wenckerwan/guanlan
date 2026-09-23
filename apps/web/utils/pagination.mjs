/** Normalize a requested page against a result count. */
export function normalizePage(value, total, perPage) {
  const pageSize = Math.max(1, Math.floor(Number(perPage) || 1))
  const pageCount = Math.max(1, Math.ceil(Math.max(0, Number(total) || 0) / pageSize))
  const requested = Math.floor(Number(value) || 1)
  return Math.min(pageCount, Math.max(1, requested))
}

export function pageCount(total, perPage) {
  const pageSize = Math.max(1, Math.floor(Number(perPage) || 1))
  return Math.max(1, Math.ceil(Math.max(0, Number(total) || 0) / pageSize))
}

export function pageWindow(page, total, perPage, radius = 2) {
  const count = pageCount(total, perPage)
  const current = normalizePage(page, total, perPage)
  const spread = Math.max(0, Math.floor(Number(radius) || 0))
  const start = Math.max(1, Math.min(current - spread, count - spread * 2))
  const end = Math.min(count, Math.max(current + spread, spread * 2 + 1))
  return Array.from({ length: end - start + 1 }, (_, index) => start + index)
}

export function paginationState(page, total, perPage) {
  const currentPage = normalizePage(page, total, perPage)
  const pages = pageCount(total, perPage)
  return {
    page: currentPage,
    pages,
    hasPrevious: currentPage > 1,
    hasNext: currentPage < pages,
    start: total > 0 ? (currentPage - 1) * perPage + 1 : 0,
    end: Math.min(currentPage * perPage, total),
  }
}

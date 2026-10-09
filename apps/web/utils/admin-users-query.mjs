const scalar = value => String(Array.isArray(value) ? value[0] ?? '' : value ?? '')
const enumeration = (value, allowed) => allowed.includes(scalar(value)) ? scalar(value) : ''
const integer = (value, fallback, max) => {
  const number = Number(scalar(value))
  return Number.isFinite(number) && scalar(value) !== '' ? Math.min(max, Math.max(1, Math.floor(number))) : fallback
}

export function userListFilters(query = {}) {
  return {
    q: scalar(query.q).trim(),
    role: enumeration(query.role, ['user', 'admin']),
    status: enumeration(query.status, ['active', 'disabled']),
    userGroup: enumeration(query.userGroup, ['user', 'vip', 'svip', 'sssvip']),
    page: integer(query.page, 1, 2147483647),
    perPage: integer(query.perPage, 20, 100),
  }
}

export function userListQuery(filters) {
  const result = {}
  for (const key of ['q', 'role', 'status', 'userGroup']) if (filters[key]) result[key] = String(filters[key])
  if (filters.page > 1) result.page = String(filters.page)
  if (filters.perPage !== 20) result.perPage = String(filters.perPage)
  return result
}

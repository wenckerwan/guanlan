export function filterPracticeQuestions(questions, filter, states, favorites) {
  const keyword = String(filter.keyword ?? '').trim().toLocaleLowerCase()
  const saved = new Set(favorites)
  return questions.filter(question => {
    const ref = question.questionRef
    const state = states[ref]
    const status = filter.status
    if (filter.module !== 'all' && question.module !== filter.module) return false
    if (filter.type !== 'all' && question.type !== filter.type) return false
    if (status === 'todo' && state?.attemptCount) return false
    if (status === 'done' && !state?.attemptCount) return false
    if (status === 'wrong' && state?.lastIsRight !== false) return false
    if (status === 'saved' && !saved.has(ref)) return false
    return !keyword || [ref, question.stem, question.event, question.knowledge, ...Object.values(question.options ?? {})]
      .join(' ').toLocaleLowerCase().includes(keyword)
  })
}

export function isPracticeBank(value) {
  if (!value || typeof value !== 'object' || typeof value.bankId !== 'string' || typeof value.contentVersion !== 'string' || !Number.isInteger(value.questionCount) || !Array.isArray(value.questions)) return false
  return value.questions.every(question => question && typeof question.questionRef === 'string' && typeof question.questionVersion === 'string' && ['single', 'multi'].includes(question.type) && typeof question.module === 'string' && typeof question.stem === 'string' && question.options && typeof question.options === 'object' && ['A', 'B', 'C', 'D'].every(key => typeof question.options[key] === 'string'))
}

export function pagePracticeQuestions(items, requestedPage, pageSize = 6) {
  const total = items.length
  const pages = Math.max(1, Math.ceil(total / pageSize))
  const page = Math.min(pages, Math.max(1, Math.trunc(Number(requestedPage) || 1)))
  return { items: items.slice((page - 1) * pageSize, page * pageSize), page, pages, total }
}

export function outboxKey(userId, bankId, contentVersion) {
  return `guanlan.practice.outbox.v1:${encodeURIComponent(String(userId))}:${encodeURIComponent(bankId)}:${encodeURIComponent(contentVersion)}`
}

export function readOutbox(storage, key) {
  const events = []
  try {
    for (let index = 0; index < storage.length; index++) {
      const entryKey = storage.key(index)
      if (!entryKey?.startsWith(`${key}:`)) continue
      try {
        const event = JSON.parse(storage.getItem(entryKey) ?? 'null')
        if (event && typeof event.eventId === 'string') events.push(event)
      } catch { /* Skip a corrupt event without deleting it. */ }
    }
  } catch {
    return events
  }
  return events
}

export function enqueueAttempt(storage, key, event) {
  const entryKey = `${key}:${encodeURIComponent(event.eventId)}`
  if (!storage.getItem(entryKey)) storage.setItem(entryKey, JSON.stringify(event))
  return readOutbox(storage, key)
}

export function removeAttempt(storage, key, eventId) {
  storage.removeItem(`${key}:${encodeURIComponent(eventId)}`)
  return readOutbox(storage, key)
}

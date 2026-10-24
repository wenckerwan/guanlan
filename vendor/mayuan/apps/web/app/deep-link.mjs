const BASE_URL = 'https://guanlan.invalid'

export function conceptIdFromUrl(value) {
  try {
    const url = new URL(String(value), BASE_URL)
    if (!/^\/(?:mayuan\/)?$/.test(url.pathname)) return null
    const id = url.searchParams.get('concept')?.trim()
    return id || null
  } catch {
    return null
  }
}

export function withConceptId(value, id) {
  const url = new URL(String(value), BASE_URL)
  if (id) url.searchParams.set('concept', String(id))
  else url.searchParams.delete('concept')
  return `${url.pathname}${url.search}${url.hash}`
}

export function resolveConcept(url, nodes) {
  const id = conceptIdFromUrl(url)
  const node = id ? nodes.find(node => node.id === id) || null : null
  return { node, missing: Boolean(id && !node) }
}
export function readerAnchor(id, section) {
  return `concept-${encodeURIComponent(id)}-${section}`
}
export function conceptCitation(node, content, url) {
  const sources = (node.sources || []).map(s => [s.label, s.locator].filter(Boolean).join('：')).filter(Boolean)
  return [ `观澜·马原知识宇宙：${node.title}`, ...sources,
    content.contentVersion ? `内容版本：${content.contentVersion}` : '',
    node.updatedAt ? `概念更新：${node.updatedAt}` : '',
    content.updatedAt ? `内容更新：${content.updatedAt}` : '', url ].filter(Boolean).join('\n')
}
export async function copyText(text, clipboard) {
  try {
    if (!clipboard?.writeText) return { copied: false, text }
    await clipboard.writeText(text)
    return { copied: true, text }
  } catch { return { copied: false, text } }
}

export function readerSectionFromUrl(value, id) {
  try {
    const hash = new URL(String(value), BASE_URL).hash.slice(1)
    return ['title', 'principle', 'usage', 'relations', 'sources']
      .map(section => readerAnchor(id, section)).find(anchor => anchor === hash) || null
  } catch { return null }
}

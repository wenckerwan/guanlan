import { marked } from 'marked'
import sanitizeHtml from 'sanitize-html'

export function renderReportMarkdown(markdown) {
  if (!markdown) return ''
  try {
    const rendered = marked.parse(String(markdown), { async: false, html: false })
    return sanitizeHtml(rendered, {
      allowedTags: [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'strong', 'em', 'del',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'table', 'thead', 'tbody', 'tr',
        'th', 'td', 'a',
      ],
      allowedAttributes: {
        a: ['href', 'title'],
        code: ['class'],
      },
      allowedSchemes: ['http', 'https', 'mailto'],
      allowProtocolRelative: false,
      allowVulnerableTags: false,
    })
  } catch {
    const escaped = String(markdown).replace(/[&<>"']/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char])
    return `<pre>${escaped}</pre>`
  }
}

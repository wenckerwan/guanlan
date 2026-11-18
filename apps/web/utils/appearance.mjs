/** Only allow public appearance values into the document's theme attribute. */
export function normalizeAppearance(value) {
  return value === 'light' || value === 'dark' ? value : 'system'
}

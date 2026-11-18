// The retired mistake-analysis feature stored provider credentials in this key.
// Run independently of authentication, even for guests and malformed old values.
export default defineNuxtPlugin(() => {
  try {
    window.localStorage.removeItem('ai_analysis_config')
  } catch {
    // Storage may be unavailable (privacy mode / browser policy). Never block startup.
  }
})

export default defineNuxtPlugin((nuxtApp) => {
  if (import.meta.dev) return
  useHead({ script: [{ src: '/visit-tracker.js', defer: true, 'data-api-base': useRuntimeConfig().public.apiBase as string }] })
  nuxtApp.hook('page:finish', () => {
    window.dispatchEvent(new CustomEvent('guanlan:navigation'))
  })
})

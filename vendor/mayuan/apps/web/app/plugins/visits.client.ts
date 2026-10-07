export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.hook('page:finish', () => {
    window.dispatchEvent(new CustomEvent('guanlan:navigation'))
  })
})

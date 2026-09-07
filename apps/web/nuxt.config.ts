export default defineNuxtConfig({
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  typescript: { strict: true, typeCheck: false },
  runtimeConfig: {
    public: { apiBase: process.env.NUXT_PUBLIC_API_BASE || '/api/v1' },
  },
  nitro: {
    externals: { inline: ['vue', '@vue/server-renderer'] },
  },
  app: { head: { htmlAttrs: { lang: 'zh-CN' } } },
})

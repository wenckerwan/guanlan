export default defineNuxtConfig({
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  typescript: { strict: true, typeCheck: false },
  runtimeConfig: {
    apiInternalBase: process.env.NUXT_API_INTERNAL_BASE || 'http://api:9501/api/v1',
    public: { apiBase: process.env.NUXT_PUBLIC_API_BASE || '/api/v1' },
  },
  nitro: {
    externals: { inline: ['vue', '@vue/server-renderer'] },
  },
  app: { head: { htmlAttrs: { lang: 'zh-CN' } } },
})

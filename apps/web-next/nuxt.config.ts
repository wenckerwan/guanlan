export default defineNuxtConfig({
  devtools: { enabled: false },
  css: ['~/assets/workspace.css'],
  typescript: { strict: true },
  app: { head: { htmlAttrs: { lang: 'zh-CN' }, title: '观澜 · 学习工作台' } },
})

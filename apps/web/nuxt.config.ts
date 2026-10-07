export default defineNuxtConfig({
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  typescript: { strict: true, typeCheck: false },
  modules: ['@vite-pwa/nuxt'],
  pwa: {
    registerType: 'prompt',
    injectRegister: 'auto',
    manifest: {
      name: '观澜考研政治知识库',
      short_name: '观澜',
      lang: 'zh-CN',
      display: 'standalone',
      start_url: '/',
      scope: '/',
      theme_color: '#b4232d',
      background_color: '#fbfaf7',
      icons: [
        { src: '/pwa-192.png', sizes: '192x192', type: 'image/png' },
        { src: '/pwa-512.png', sizes: '512x512', type: 'image/png' },
        { src: '/pwa-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
      ],
    },
    workbox: {
      // 默认 glob 不会收进 _nuxt 的 js/css，必须显式列出，否则冷启动离线时页面无样式、无 hydration
      globPatterns: ['**/*.{js,css,html,webmanifest,png,ico,svg,json}'],
      // 导航请求先走网络（SSR），失败回退到预渲染的离线页
      navigateFallback: '/offline',
      navigateFallbackDenylist: [/^\/api\//],
      // 仅缓存公开只读内容；用户态/写操作/未知 API 一律直连网络（规则与测试见 utils/sw-cache-rules.mjs、tests/pwa-cache.test.mjs）
      runtimeCaching: [
        {
          urlPattern: new RegExp('^/api/v1/(home|subjects|papers|questions|analysis|hotspots|predictions|mocks)(/|$)'),
          handler: 'StaleWhileRevalidate',
          options: {
            cacheName: 'guanlan-content-v1',
            expiration: { maxEntries: 200, maxAgeSeconds: 604800 },
            cacheableResponse: { statuses: [200] },
          },
        },
        {
          urlPattern: new RegExp('^/api/v1/history/events(/|$)'),
          handler: 'StaleWhileRevalidate',
          options: {
            cacheName: 'guanlan-content-v1',
            expiration: { maxEntries: 200, maxAgeSeconds: 604800 },
            cacheableResponse: { statuses: [200] },
          },
        },
      ],
    },
    devOptions: { enabled: false },
  },
  routeRules: {
    // 离线兜底页静态化后才能被 Service Worker 预缓存
    '/offline': { prerender: true },
  },
  runtimeConfig: {
    apiInternalBase: process.env.NUXT_API_INTERNAL_BASE || 'http://api:9501/api/v1',
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || '/api/v1',
      mayuanBase: process.env.NUXT_PUBLIC_MAYUAN_BASE || '/mayuan/',
      historyBase: process.env.NUXT_PUBLIC_HISTORY_BASE || '/history/',
    },
  },
  nitro: {
    externals: { inline: ['vue', '@vue/server-renderer'] },
  },
  app: {
    head: {
      htmlAttrs: { lang: 'zh-CN' },
      link: [
        { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' },
        { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/logo/guanlan-logo-32.png' },
        { rel: 'apple-touch-icon', sizes: '180x180', href: '/apple-touch-icon.png' },
      ],
    },
  },
})

export default defineNuxtConfig({
  ssr: false,
  devtools: { enabled: false },
  runtimeConfig: {
    apiBase: process.env.NUXT_API_BASE || "http://127.0.0.1:8086",
  },
  app: {
    // 挂载在观澜 /mayuan/ 前缀下：SPA 资源与路由均以此为基，避免 /_nuxt 404。
    baseURL: "/mayuan/",
    head: {
      title: "马原知识宇宙 · 知识探索",
      htmlAttrs: { lang: "zh-CN" },
      meta: [
        { name: "viewport", content: "width=device-width, initial-scale=1" },
      ],
    },
  },
  vite: {
    build: {
      rollupOptions: {
        output: {
          manualChunks(id: string) {
            // three.js 独立分包：随懒加载的 UniverseScene 按需下载，不进首包
            if (id.includes("node_modules/three")) return "three";
          },
        },
      },
    },
  },
});

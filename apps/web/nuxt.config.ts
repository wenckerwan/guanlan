export default defineNuxtConfig({
  ssr: false,
  devtools: { enabled: false },
  runtimeConfig: {
    apiBase: process.env.NUXT_API_BASE || "http://127.0.0.1:8086",
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
  app: {
    head: {
      title: "马原知识宇宙 · 学习工作台",
      htmlAttrs: { lang: "zh-CN" },
      meta: [
        { name: "viewport", content: "width=device-width, initial-scale=1" },
      ],
    },
  },
});

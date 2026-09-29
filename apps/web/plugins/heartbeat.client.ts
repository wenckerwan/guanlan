/**
 * 学习时长心跳：登录用户浏览页面时每 30 秒上报一次浏览时长。
 * 页面不可见（切后台/最小化）时暂停；单次上报 clamp 到 120 秒以内。
 */
export default defineNuxtPlugin(() => {
  if (!import.meta.client) return

  const INTERVAL = 30_000
  const config = useRuntimeConfig()
  const apiBase = config.public.apiBase as string

  let timer: ReturnType<typeof setInterval> | null = null

  async function report() {
    if (document.visibilityState !== 'visible') return
    const token = readAuthToken()
    if (!token) return
    try {
      await $fetch('/stats/heartbeat', {
        method: 'POST',
        baseURL: apiBase,
        headers: { Authorization: `Bearer ${token}` },
        body: { seconds: 30, path: window.location.pathname },
        timeout: 10_000,
      })
    } catch {
      // 静默失败：统计上报不影响浏览
    }
  }

  function start() {
    if (timer) return
    timer = setInterval(report, INTERVAL)
  }

  function stop() {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
  }

  onNuxtReady(() => {
    start()
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        start()
        void report()
      } else {
        stop()
      }
    })
  })
})

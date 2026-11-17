let loading

export function loadTurnstile() {
  if (window.turnstile) return Promise.resolve(window.turnstile)
  if (loading) return loading
  loading = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    const fail = () => { clearTimeout(timer); script.remove(); loading = undefined; reject(new Error('验证组件加载失败，请重试')) }
    const timer = setTimeout(fail, 20000)
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
    script.async = true
    script.onerror = fail
    script.onload = () => {
      if (!window.turnstile) return fail()
      clearTimeout(timer)
      resolve(window.turnstile)
    }
    document.head.appendChild(script)
  })
  return loading
}

export function mountChallenge(api, element, sitekey, update, failed = () => {}) {
  let active = true
  const clear = () => { if (active) update('') }
  const id = api.render(element, {
    sitekey, action: 'register', theme: 'auto', size: 'flexible',
    callback: token => { if (active) update(token) },
    'expired-callback': clear,
    'timeout-callback': clear,
    'error-callback': () => { clear(); if (active) failed(); return true },
  })
  return {
    reset() { clear(); if (active) api.reset(id) },
    remove() { clear(); active = false; api.remove(id) },
  }
}

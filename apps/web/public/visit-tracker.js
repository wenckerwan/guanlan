(function () {
  if (window.GuanlanVisitTracker) return;
  const options = document.currentScript?.dataset || {};
  const base = (options.apiBase || '/api/v1').replace(/\/$/, '');
  let pending = false;
  let started = false;

  function eligible() {
    return location.protocol !== 'file:' &&
      !['localhost', '127.0.0.1', '[::1]', '::1'].includes(location.hostname) &&
      !/^\/(admin|offline)(\/|$)/.test(location.pathname) &&
      document.visibilityState === 'visible' && navigator.onLine !== false;
  }

  function publish(data) {
    window.GuanlanVisits = data;
    window.dispatchEvent(new CustomEvent('guanlan:visits', { detail: data }));
  }

  async function request(path, method) {
    const response = await fetch(base + path, {
      method, credentials: 'same-origin', cache: 'no-store',
      signal: AbortSignal.timeout(8000),
    });
    if (!response.ok) throw new Error('Visit statistics unavailable');
    const { data } = await response.json();
    if (!data || !Number.isSafeInteger(data.total) || !Number.isSafeInteger(data.today)) {
      throw new Error('Invalid visit statistics');
    }
    return data;
  }

  async function report() {
    if (pending || !eligible()) return;
    pending = true;
    started = true;
    try {
      const register = async function () {
        if (!eligible()) return;
        const current = await request('/stats/visits', 'GET');
        publish(current);
        publish(await request('/stats/visit', 'POST'));
      };
      // Serializes first-cookie initialization across tabs on the same origin.
      if (navigator.locks) await navigator.locks.request('guanlan-visit', register);
      else await register();
    } catch {
      publish(null);
    } finally {
      pending = false;
    }
  }

  window.GuanlanVisitTracker = { report };
  window.addEventListener('guanlan:navigation', report);
  window.addEventListener('visibilitychange', function () { if (!started) void report(); });
  void report();
})();

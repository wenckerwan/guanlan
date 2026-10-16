export async function loadContent(api, cookie) {
  let session;
  try {
    session = await api('/session');
  } catch (error) {
    if (error.status !== 401) throw error;
    // The backend clears an expired stored identity on its first 401.
    session = await api('/session');
  }
  if (session.mode === 'guanlan') {
    const entry = cookie.split(';').map(v => v.trim()).find(v => v.startsWith('guanlan.token='));
    const token = entry ? decodeURIComponent(entry.slice('guanlan.token='.length)) : '';
    if (!token) throw Error('请先登录观澜后访问马原知识宇宙');
    await api('/guanlan/validate', { token });
  }
  return api('/content');
}

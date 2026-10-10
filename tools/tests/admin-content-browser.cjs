const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const base = process.env.ADMIN_BROWSER_BASE || 'http://127.0.0.1:3209';
    const context = await browser.newContext();
    const admin = { id: 500, email: 'admin@example.test', displayName: 'Admin', role: 'admin', status: 'active', userGroup: 'user' };
    await context.addCookies([{ name: 'guanlan.token', value: 'test-token', url: base }]);
    await context.addInitScript(user => localStorage.setItem('guanlan.user', JSON.stringify(user)), admin);
    const articles = Object.fromEntries(['hotspots', 'analysis'].map(kind => [kind, Array.from({ length: 121 }, (_, i) => ({ id: i + 1, slug: `${kind}-${i + 1}`, title: `文章${i + 1}`, summary: i === 0 ? 'literal %_ text' : '摘要', status: 'published', period: i % 2 ? '2026年10月' : '2026年9月', category: i % 2 ? '选择题规律' : '材料分析', priority: 'A', commentMode: 'open' }))]));
    const mocks = Array.from({ length: 25 }, (_, i) => ({ slug: `mock-${i}`, title: `模拟卷${i + 1}`, questionCount: 33, totalScore: 100, durationMinutes: 180 }));
    const papers = [{ id: 1, pid: 'paper-a', year: 2026, label: '试卷A', kind: '统考', questionCount: 121, totalScore: 100 }, { id: 2, pid: 'paper-b', year: 2025, label: '试卷B', kind: '统考', questionCount: 2, totalScore: 100 }];
    let failure = false, delayedPaper = false;
    const envelope = (items, params) => {
      const perPage = Math.min(100, Number(params.get('perPage') || 20));
      const page = Math.max(1, Math.min(Number(params.get('page') || 1), Math.ceil(items.length / perPage) || 1));
      return { items: items.slice((page - 1) * perPage, page * perPage), total: items.length, page, perPage };
    };
    await context.route('**/api/v1/**', async route => {
      const req = route.request(), url = new URL(req.url()), q = url.searchParams;
      let data = [];
      if (url.pathname.endsWith('/auth/me')) data = { user: admin };
      else if (/\/admin\/(hotspots|analysis)\/\d+$/.test(url.pathname)) {
        const [, kind, id] = url.pathname.match(/\/admin\/(hotspots|analysis)\/(\d+)$/);
        const item = articles[kind].find(item => item.id === Number(id));
        if (req.method() === 'DELETE') articles[kind] = articles[kind].filter(candidate => candidate !== item);
        else Object.assign(item, req.postDataJSON());
        data = item;
      } else if (/\/admin\/(hotspots|analysis)$/.test(url.pathname)) {
        const kind = url.pathname.split('/').pop();
        if (failure) { await route.fulfill({ status: 500, json: { message: 'test error' } }); return; }
        if (req.method() === 'POST') {
          data = { id: 999, slug: `${kind}-new`, status: 'published', ...req.postDataJSON() }; articles[kind].push(data);
        } else {
          const selected = articles[kind].filter(item => (!q.get('q') || `${item.title} ${item.summary}`.includes(q.get('q'))) && ['status', 'period', 'category'].every(key => !q.get(key) || item[key] === q.get(key))).sort((a, b) => b.id - a.id);
          data = { ...envelope(selected, q), filters: { periods: ['2026年9月', '2026年10月'], categories: ['材料分析', '选择题规律'] } };
          if (q.get('q') === '文章1') await new Promise(resolve => setTimeout(resolve, 500));
        }
      } else if (url.pathname.endsWith('/admin/mocks')) data = envelope(mocks, q);
      else if (url.pathname.endsWith('/admin/papers')) data = envelope(papers, q);
      else if (/\/admin\/papers\/.+\/questions$/.test(url.pathname)) {
        const pid = url.pathname.split('/').at(-2), count = pid === 'paper-a' ? 121 : 2;
        data = envelope(Array.from({ length: count }, (_, i) => ({ id: `${pid}-${i + 1}`, no: i + 1, stem: `${pid}题目${i + 1}`, moduleName: '马原', answer: 'A' })), q);
        if (pid === 'paper-a' && delayedPaper) await new Promise(resolve => setTimeout(resolve, 700));
      }
      await route.fulfill({ json: { data } });
    });
    const page = await context.newPage(), errors = [];
    page.setDefaultNavigationTimeout(60000);
    page.on('pageerror', error => errors.push(error.message));
    const submit = () => page.getByRole('button', { name: '筛选', exact: true }).click();
    const records = page.locator('.record-list li');
    const waitTotal = n => page.getByRole('heading', { name: new RegExp(`共 ${n} (条|篇)`) }).waitFor();
    for (const [kind, special, label, value] of [['hotspots', 'period', '期次', '2026年9月'], ['analysis', 'category', '分类', '材料分析']]) {
      await page.goto(`${base}/admin/${kind}`); await waitTotal(121); assert.equal(await records.count(), 20);
      await page.getByRole('button', { name: '下一页', exact: true }).click(); await page.waitForURL(url => url.searchParams.get('page') === '2');
      await records.filter({ hasText: '文章101' }).waitFor();
      await page.goto(`${base}/admin/${kind}?q=文章&status=published&${special}=${encodeURIComponent(value)}&page=2&perPage=50`); await waitTotal(61);
      assert.equal(await records.count(), 11); assert.equal(await page.getByLabel('标题或摘要', { exact: true }).inputValue(), '文章');
      assert.equal(await page.getByLabel(`${label}筛选`, { exact: true }).inputValue(), value);
      assert.equal(await page.getByLabel('状态筛选', { exact: true }).inputValue(), 'published');
      assert.equal(await page.getByLabel('每页条数', { exact: true }).inputValue(), '50');
      await page.reload(); await waitTotal(61); assert.equal(await records.count(), 11);
      assert.equal(await page.getByLabel(`${label}筛选`, { exact: true }).locator('option').count(), 3);
      failure = true; await submit(); await page.getByRole('alert').waitFor(); assert.equal(await records.count(), 0);
      failure = false; await page.getByRole('button', { name: '重试', exact: true }).click(); await waitTotal(61);
      await page.getByRole('button', { name: '重置筛选', exact: true }).click(); await waitTotal(121);
      await page.getByLabel('标题或摘要', { exact: true }).fill('%_'); await submit(); await waitTotal(1);
      await records.filter({ hasText: '文章1' }).getByRole('button', { name: '已发布', exact: true }).click(); await waitTotal(1);
      await page.getByLabel('状态筛选', { exact: true }).selectOption('hidden'); await submit(); await waitTotal(1);
      await records.getByRole('button', { name: '已隐藏', exact: true }).click(); await waitTotal(0); await page.getByText('没有匹配的内容。', { exact: true }).waitFor();
      await page.getByRole('button', { name: '重置筛选', exact: true }).click(); await waitTotal(121);
      await page.getByLabel('标题或摘要', { exact: true }).fill('文章1'); await submit();
      await page.getByLabel('标题或摘要', { exact: true }).fill('文章99'); await submit(); await waitTotal(1); await page.waitForTimeout(650);
      await records.filter({ hasText: '文章99' }).waitFor(); assert.equal(await records.count(), 1);
      await page.goto(`${base}/admin/${kind}?status=published&page=7`); await waitTotal(121); assert.equal(await records.count(), 1);
      await records.getByRole('button', { name: '已发布', exact: true }).click(); await waitTotal(120);
      await page.waitForURL(url => url.searchParams.get('page') === '6'); assert.equal(await records.count(), 20);
      console.log(`PASS ${kind}: >100, URL restore, filters, literal search, retry, stale response, mutation and final page`);
    }
    await page.goto(`${base}/admin/mocks`); await page.getByRole('heading', { name: '模拟押题（25 套）' }).waitFor(); assert.equal(await page.locator('tbody tr').count(), 20);
    await page.getByRole('button', { name: '下一页', exact: true }).click(); await page.getByText('模拟卷25', { exact: true }).waitFor(); assert.equal(await page.locator('tbody tr').count(), 5);
    await page.reload(); await page.getByText('模拟卷25', { exact: true }).waitFor(); console.log('PASS mocks paging and refresh');
    await page.goto(`${base}/admin/papers`); await page.getByText('试卷A', { exact: true }).waitFor();
    const paperRow = name => page.getByRole('row').filter({ hasText: name });
    await paperRow('试卷A').getByRole('button', { name: '查看题目', exact: true }).click(); await page.getByText('共 121 道题', { exact: true }).waitFor(); assert.equal(await page.locator('.admin-detail-panel tbody tr').count(), 20);
    for (let i = 0; i < 6; i++) { await page.locator('.admin-detail-panel').getByRole('button', { name: '下一页', exact: true }).click(); await page.getByText(`paper-a题目${(i + 1) * 20 + 1}`, { exact: true }).waitFor(); }
    assert.equal(await page.locator('.admin-detail-panel tbody tr').count(), 1);
    delayedPaper = true;
    await page.locator('.admin-detail-panel').getByRole('button', { name: '上一页', exact: true }).click();
    await paperRow('试卷B').getByRole('button', { name: '查看题目', exact: true }).click(); await page.getByText('paper-b题目1', { exact: true }).waitFor();
    await page.waitForTimeout(900); assert.equal(await page.locator('.admin-detail-panel tbody tr').count(), 2); assert.equal(await page.getByText('paper-a题目101', { exact: true }).count(), 0);
    await page.locator('.admin-detail-panel').getByText('第 1 / 1 页', { exact: true }).waitFor();
    assert.deepEqual(errors, []); console.log('PASS >100 questions, independent paging, switch reset and stale paper response');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

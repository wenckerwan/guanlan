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
    // Keep SSR independent of an unavailable local API; client auth and all API data are mocked.
    await context.route(base + '/admin/**', route => {
      if (!route.request().isNavigationRequest()) return route.continue();
      const headers = { ...route.request().headers() }; delete headers.cookie; return route.continue({ headers });
    });
    let failure = false, requests = [], auditFailure = 0;
    const dates = (from, to) => { const result = []; for (let value = Date.parse(from); value <= Date.parse(to); value += 86400000) result.push(new Date(value).toISOString().slice(0, 10)); return result; };
    const detail = { long: '完整记录'.repeat(150), nested: { literal: '<img src=x onerror="window.__auditXss=1">', items: [null, false, 0, { final: 'END_OF_DETAIL' }] } };
    await context.route('**/api/v1/**', async route => {
      const url = new URL(route.request().url()), q = url.searchParams;
      requests.push(url);
      let data = [];
      if (url.pathname.endsWith('/auth/me')) data = { user: admin };
      else if (url.pathname.endsWith('/admin/overview')) {
        if (failure) { await route.fulfill({ status: 500, json: { message: '统计服务暂时失败' } }); return; }
        const from = q.get('from'), to = q.get('to');
        if (from === '2026-01-01') await new Promise(resolve => setTimeout(resolve, 850));
        const days = dates(from, to), count = from === '2026-01-01' ? 99 : 7;
        data = { counts: { users: 300, papers: 12 }, recentUsers: [{ id: 1, email: 'new@example.test', role: 'user', createdAt: '2026-10-09T16:30:00Z' }], statusCounts: { hotspots: { published: 8, hidden: 1 } }, recentAudit: [], statistics: { from, to, dates: days, timezone: 'Asia/Shanghai', timestampTimezone: 'UTC', studyDateTimezone: 'UTC', updatedAt: '2026-10-10T11:30:00+08:00', totals: { registrations: count, attempts: 12, correctAttempts: 6, visits: 45, studySeconds: 3661, activeStudyAccounts: 3 }, registrationTrend: { [from]: count }, attemptsTrend: { [to]: 12 }, visitTrend: { [from]: 45 }, studyTrend: { [to]: 3661 } } };
      } else if (url.pathname.endsWith('/admin/audit-logs')) {
        if (auditFailure) { await route.fulfill({ status: auditFailure, json: { message: auditFailure === 403 ? '无权查看审计' : '筛选参数无效' } }); return; }
        if (q.get('action') === 'slow.') await new Promise(resolve => setTimeout(resolve, 800));
        const page = Number(q.get('page') || 1), perPage = Number(q.get('perPage') || 20), total = 41;
        data = { total, page, perPage, items: Array.from({ length: Math.min(perPage, total - (page - 1) * perPage) }, (_, i) => ({ id: (page - 1) * perPage + i + 1, adminId: 500, adminEmail: 'admin@example.test', action: q.get('action') || 'article.restore', targetType: q.get('targetType') || 'hotspot', targetId: q.get('targetId') || '5', createdAt: '2026-10-09T16:30:00Z', detail })) };
      }
      await route.fulfill({ json: { data } });
    });
    const page = await context.newPage(), errors = [];
    page.setDefaultNavigationTimeout(60000);
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${base}/admin/overview?from=2026-10-01&to=2026-10-10`);
    await page.getByRole('heading', { name: '区间统计：2026-10-01 — 2026-10-10' }).waitFor();
    assert.equal(await page.getByLabel('开始日期', { exact: true }).inputValue(), '2026-10-01');
    assert.equal(await page.locator('.metric-trend').count(), 4);
    await page.getByText('访问次数（非人数）', { exact: true }).waitFor();
    await page.getByText(/原始 UTC 自然日记录/).waitFor();
    await page.getByText('1 小时 1 分 1 秒', { exact: true }).waitFor();
    await page.getByText('2026-10-10 00:30:00', { exact: true }).waitFor();
    await page.getByText('账号创建趋势数据明细（10 日）', { exact: true }).focus();
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('.metric-trend').first().locator('details').getAttribute('open'), '');
    assert.equal(await page.locator('.metric-trend').first().locator('tbody tr').count(), 10);
    await page.reload(); await page.getByRole('heading', { name: /区间统计/ }).waitFor();
    failure = true; await page.getByRole('button', { name: '查询统计' }).click(); await page.getByRole('alert').waitFor();
    assert.equal(await page.locator('.metric-trend').count(), 0); assert.equal(await page.locator('.range-totals').count(), 0);
    failure = false; await page.getByRole('button', { name: '重试', exact: true }).click(); await page.getByRole('heading', { name: /区间统计/ }).waitFor();
    await page.getByLabel('开始日期', { exact: true }).fill('2026-01-01'); await page.getByRole('button', { name: '查询统计' }).click();
    await page.getByLabel('开始日期', { exact: true }).fill('2026-10-02'); await page.getByRole('button', { name: '查询统计' }).click();
    await page.getByRole('heading', { name: '区间统计：2026-10-02 — 2026-10-10' }).waitFor(); await page.waitForTimeout(1000);
    assert.equal(await page.locator('.range-totals .stat-cell').first().locator('strong').innerText(), '7');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${base}/admin/overview?from=2025-10-10&to=2026-10-10`); await page.getByRole('heading', { name: /区间统计/ }).waitFor();
    assert.equal(await page.locator('.metric-trend').first().getByRole('img').getAttribute('aria-label'), '账号创建趋势，共 366 日；完整数值见下方数据表');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'mobile overview overflow');
    console.log('PASS overview range URL/refresh, true totals and timezone notes, four 366-day accessible trends, error/retry, latest response and mobile');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${base}/admin/audit-logs?action=comment.%25_&adminId=500&targetType=comment&targetId=5&from=2026-10-01&to=2026-10-10`);
    await page.getByRole('heading', { name: '共 41 条操作记录' }).waitFor();
    const latestAudit = requests.filter(url => url.pathname.endsWith('/admin/audit-logs')).at(-1);
    assert.equal(latestAudit.searchParams.get('action'), 'comment.%_');
    for (const [key, value] of Object.entries({ adminId: '500', targetType: 'comment', targetId: '5', from: '2026-10-01', to: '2026-10-10' })) assert.equal(latestAudit.searchParams.get(key), value);
    await page.getByText('查看完整详情 #1', { exact: true }).click();
    assert.deepEqual(JSON.parse(await page.locator('tbody pre').first().innerText()), detail);
    assert.equal(await page.locator('tbody img').count(), 0); assert.equal(await page.evaluate(() => window.__auditXss), undefined);
    assert.equal(await page.locator('tbody time').first().innerText(), '2026-10-10 00:30:00');
    await page.getByRole('button', { name: '下一页', exact: true }).click(); await page.getByText('第 2 / 3 页 · 共 41 条', { exact: true }).waitFor();
    await page.reload(); await page.getByText('第 2 / 3 页 · 共 41 条', { exact: true }).waitFor();
    await page.getByRole('button', { name: '下一页', exact: true }).click(); await page.getByText('第 3 / 3 页 · 共 41 条', { exact: true }).waitFor();
    assert.equal(await page.locator('tbody tr').count(), 1);
    auditFailure = 403; await page.getByRole('button', { name: '筛选', exact: true }).click(); await page.getByRole('alert').waitFor();
    assert.equal(await page.locator('tbody tr').count(), 0); await page.getByText('无权查看审计', { exact: true }).waitFor();
    auditFailure = 0; await page.getByRole('button', { name: '重试', exact: true }).click(); await page.getByRole('heading', { name: '共 41 条操作记录' }).waitFor();
    auditFailure = 422; await page.goto(`${base}/admin/audit-logs?adminId=bad&from=invalid`); await page.getByRole('alert').waitFor(); await page.getByText('筛选参数无效', { exact: true }).waitFor();
    auditFailure = 0; await page.getByRole('button', { name: '重试', exact: true }).click(); await page.getByRole('heading', { name: '共 41 条操作记录' }).waitFor();
    await page.getByLabel('操作前缀', { exact: true }).fill('slow.'); await page.getByRole('button', { name: '筛选', exact: true }).click();
    await page.getByLabel('操作前缀', { exact: true }).fill('prediction.'); await page.getByRole('button', { name: '筛选', exact: true }).click();
    await page.locator('tbody tr').first().getByText('prediction.', { exact: true }).waitFor(); await page.waitForTimeout(1000);
    assert.equal(await page.getByText('slow.', { exact: true }).count(), 0);
    await page.setViewportSize({ width: 390, height: 844 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'mobile audit overflow');
    assert.deepEqual(errors, []);
    console.log('PASS audit literal prefix/combo URL, complete escaped nested JSON, Beijing UTC rollover, full totals/paging/refresh, 403/422/retry, latest response and mobile');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

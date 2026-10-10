const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const base = process.env.ADMIN_BROWSER_BASE || 'http://127.0.0.1:3210';
    const context = await browser.newContext();
    const admin = { id: 500, email: 'admin@example.test', displayName: 'Admin', role: 'admin', status: 'active', userGroup: 'user' };
    await context.addCookies([{ name: 'guanlan.token', value: 'test-token', url: base }]);
    await context.addInitScript(user => localStorage.setItem('guanlan.user', JSON.stringify(user)), admin);
    await context.route(base + '/admin/**', route => { const headers = { ...route.request().headers() }; delete headers.cookie; return route.continue({ headers }); });
    const content = '<img src=x onerror="window.__commentsXss=1">\n' + '完整评论'.repeat(150) + ' END_OF_CONTENT';
    let rows = Array.from({ length: 41 }, (_, i) => ({ id: 41 - i, articleType: 'hotspot', articleSlug: 'article-a', floor: i + 1, content, status: 'pending', pinned: false, parentId: null, createdAt: '2026-10-10T00:30:00Z', user: { id: 5, name: '同学甲', role: 'user', userGroup: 'user' } }));
    let failure = 0, mutationFailure = false, mutationDelay = 0, reloadDelay = 0, mutationCount = 0, requestLog = [];
    await context.route('**/api/v1/**', async route => {
      const url = new URL(route.request().url()), q = url.searchParams, method = route.request().method();
      let data = [];
      if (url.pathname.endsWith('/auth/me')) data = { user: admin };
      else if (/\/admin\/comments\/\d+$/.test(url.pathname)) {
        mutationCount++; const id = Number(url.pathname.split('/').pop());
        if (mutationDelay) await new Promise(resolve => setTimeout(resolve, mutationDelay));
        if (mutationFailure) return route.fulfill({ status: 500, json: { message: '评论操作失败' } });
        if (method === 'DELETE') rows = rows.filter(row => row.id !== id);
        else { const action = route.request().postDataJSON().action; const row = rows.find(row => row.id === id); if (action === 'approve') row.status = 'approved'; else row.pinned = action === 'pin'; }
        data = {};
      } else if (url.pathname.endsWith('/admin/comments')) {
        requestLog.push(url);
        if (failure) return route.fulfill({ status: failure, json: { message: failure === 422 ? '评论筛选参数无效' : '评论列表失败' } });
        if (q.get('q') === 'slow') await new Promise(resolve => setTimeout(resolve, 800));
        if (reloadDelay) await new Promise(resolve => setTimeout(resolve, reloadDelay));
        let selected = rows.filter(row => (!q.get('status') || row.status === q.get('status')) && (!q.get('articleType') || row.articleType === q.get('articleType')) && (!q.get('articleSlug') || row.articleSlug === q.get('articleSlug')) && (!q.get('userId') || String(row.user.id) === q.get('userId')));
        if (q.get('q') === 'missing') selected = [];
        if (q.get('q') === 'slow') selected = [{ ...rows[0], content: 'STALE_COMMENT' }];
        const perPage = Math.min(100, Number(q.get('perPage') || 20)), page = Math.max(1, Math.min(Number(q.get('page') || 1), Math.ceil(selected.length / perPage) || 1));
        data = { total: selected.length, page, perPage, items: selected.slice((page - 1) * perPage, page * perPage) };
      }
      await route.fulfill({ json: { data } });
    });
    const page = await context.newPage(), errors = [];
    page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(60000); page.on('pageerror', error => errors.push(error.message));
    const heading = n => page.getByRole('heading', { name: `共 ${n} 条评论`, exact: true }).waitFor();
    const submit = () => page.getByRole('button', { name: '筛选', exact: true }).click();
    await page.goto(`${base}/admin/comments?q=%25_%3D&status=pending&articleType=hotspot&articleSlug=article-a&userId=5&userQ=%E5%90%8C%E5%AD%A6&page=2&perPage=20`);
    await heading(41);
    assert.equal(await page.getByLabel('评论正文关键词', { exact: true }).inputValue(), '%_=');
    assert.equal(await page.getByLabel('用户姓名或邮箱', { exact: true }).inputValue(), '同学');
    assert.equal(await page.locator('tbody tr').count(), 20);
    let query = requestLog.at(-1).searchParams;
    for (const [key, value] of Object.entries({ q: '%_=', status: 'pending', articleType: 'hotspot', articleSlug: 'article-a', userId: '5', userQ: '同学', page: '2', perPage: '20' })) assert.equal(query.get(key), value);
    assert.equal(await page.locator('tbody .comment-content-cell').first().innerText(), content);
    assert.equal(await page.locator('tbody img').count(), 0);
    assert.equal(await page.locator('tbody a').filter({ hasText: 'article-a' }).first().getAttribute('href'), '/hotspots/article-a');
    await page.reload(); await heading(41); await page.getByText('第 2 / 3 页 · 共 41 条', { exact: true }).waitFor();
    const reads = requestLog.length; await page.getByLabel('评论正文关键词', { exact: true }).fill('draft'); await page.waitForTimeout(150); assert.equal(requestLog.length, reads);
    await submit(); await page.waitForURL(url => url.searchParams.get('q') === 'draft' && !url.searchParams.has('page')); await heading(41);
    await page.locator('tbody tr').first().getByRole('button', { name: '同文章', exact: true }).click(); await heading(41);
    await page.locator('tbody tr').first().getByRole('button', { name: '同用户', exact: true }).click(); await heading(41);
    failure = 500; await submit(); await page.getByRole('alert').waitFor(); assert.equal(await page.locator('tbody tr').count(), 0); assert.equal(await page.getByText('没有匹配的评论。', { exact: true }).count(), 0);
    failure = 0; await page.getByRole('button', { name: '重试列表', exact: true }).click(); await heading(41);
    await page.getByLabel('评论正文关键词', { exact: true }).fill('slow'); await submit();
    await page.getByLabel('评论正文关键词', { exact: true }).fill('missing'); await submit(); await heading(0); await page.waitForTimeout(1000); assert.equal(await page.getByText('STALE_COMMENT', { exact: true }).count(), 0);
    await page.getByRole('button', { name: '重置筛选', exact: true }).click(); await heading(41);
    await page.getByLabel('评论状态', { exact: true }).selectOption('pending'); await submit(); await heading(41);
    mutationDelay = 350; reloadDelay = 350;
    await page.locator('tbody tr').first().getByRole('button', { name: '通过', exact: true }).click();
    assert.equal(await page.locator('tbody button:not([disabled])').count(), 0);
    await heading(40); assert.equal(mutationCount, 1); mutationDelay = 0; reloadDelay = 0;
    mutationFailure = true; await page.locator('tbody tr').first().getByRole('button', { name: '置顶', exact: true }).click(); await page.getByRole('alert').waitFor(); mutationFailure = false;
    await page.getByRole('button', { name: '重试操作', exact: true }).click(); await heading(40); await page.locator('tbody tr').first().getByRole('button', { name: '取消置顶', exact: true }).waitFor();
    await page.getByRole('button', { name: '下一页', exact: true }).click(); await page.getByText('第 2 / 2 页 · 共 40 条', { exact: true }).waitFor();
    // Move to an intentionally overlarge page; server canonical page must reach URL.
    await page.goto(`${base}/admin/comments?status=pending&page=999`); await heading(40); await page.waitForURL(url => url.searchParams.get('page') === '2');
    let dialogText = '';
    page.once('dialog', async dialog => { dialogText = dialog.message(); await dialog.dismiss(); });
    const beforeDelete = mutationCount; await page.locator('tbody tr').first().getByRole('button', { name: '删除', exact: true }).click(); assert.match(dialogText, /回复/); assert.equal(mutationCount, beforeDelete);
    // Last page holds one item after selecting a fixture with 21 pending records.
    rows = rows.filter(row => row.status === 'pending').slice(0, 21);
    await page.reload(); await heading(21); assert.equal(await page.locator('tbody tr').count(), 1);
    page.once('dialog', dialog => dialog.accept()); await page.locator('tbody tr').first().getByRole('button', { name: '删除', exact: true }).click(); await heading(20);
    await page.waitForURL(url => !url.searchParams.has('page')); await heading(20); assert.equal(await page.locator('tbody tr').count(), 20);
    failure = 422; await page.goto(`${base}/admin/comments?status=bad&userId=-1`); await page.getByRole('alert').waitFor(); await page.getByText('评论筛选参数无效', { exact: true }).waitFor();
    failure = 0; await page.getByRole('button', { name: '重置筛选', exact: true }).click(); await heading(20);
    rows[0].user = { id: 0, name: '已注销', role: 'user', userGroup: 'user' }; rows[0].parentId = 999;
    await page.reload(); await heading(20);
    assert.equal(await page.locator('tbody tr').first().getByRole('button', { name: '同用户', exact: true }).isDisabled(), true);
    assert.equal(await page.locator('tbody tr').first().getByRole('button', { name: '置顶', exact: true }).count(), 0);
    await page.setViewportSize({ width: 390, height: 844 }); assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    assert.deepEqual(errors, []); console.log('PASS comments URL/draft/combination/literal search/full escaped content/links/locators/retry/stale response/action locking+retry/confirmed delete/canonical paging/mobile');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

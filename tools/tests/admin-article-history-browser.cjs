const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true, args: ['--no-proxy-server'] });
  try {
    const base = process.env.ADMIN_BROWSER_BASE || 'http://127.0.0.1:3209';
    const context = await browser.newContext({ acceptDownloads: true });
    const admin = { id: 500, email: 'admin@example.test', displayName: 'Admin', role: 'admin', status: 'active', userGroup: 'user' };
    await context.addCookies([{ name: 'guanlan.token', value: 'test-token', url: base }]);
    await context.addInitScript(user => { if (window === window.top) localStorage.setItem('guanlan.user', JSON.stringify(user)); }, admin);
    const raw = '# editable\n\n**原始 Markdown** <script>window.pwned=1</script>\n';
    const article = { id: 1, slug: 'article-1', title: '当前文章', summary: '摘要', status: 'hidden', category: '材料分析', period: '2026年10月', priority: 'A', level: 'S', type: '形势与政策', tag: '政策', format: 'markdown', body: '当前正文', html: '<p>当前正文</p>', outline: [], wordCount: 4, revision: 12, contentSource: 'admin', allowComments: false };
    const old = { ...article, title: '旧文章', status: 'published', body: raw, revision: 1, allowComments: true };
    let restoreConflict = false, restores = 0, patches = 0, lastRestore, lastPatch, delayHistory = false, revisionPages = [];
    let sourceGate = null, historyGate = null;
    const gate = () => { let release, started; return { wait: new Promise(r => { release = r; }), begun: new Promise((r, reject) => { const timeout = setTimeout(() => reject(new Error('Delayed request did not start')), 15000); started = () => { clearTimeout(timeout); r(); }; }), release: () => release(), started: () => started() }; };
    await context.route('**/api/v1/**', async route => {
      const req = route.request(), url = new URL(req.url()); let data = [];
      if (url.pathname.endsWith('/auth/me')) data = { user: admin };
      else if (/\/admin\/articles\/(hotspot|analysis)\/1\/revisions$/.test(url.pathname)) {
        const page = Number(url.searchParams.get('page') || 1); revisionPages.push(page);
        data = { items: Array.from({ length: article.revision }, (_, i) => article.revision - i).slice((page - 1) * 10, page * 10).map(revision => ({ revision, title: revision === 1 ? '旧文章' : '文章', status: 'published', format: 'markdown', contentSource: 'admin', wordCount: 4, adminId: 500, createdAt: '2026-10-10T10:00:00Z' })), total: article.revision, page, perPage: 10 };
        if (historyGate) { const pending = historyGate; historyGate = null; pending.started(); await pending.wait; }
        if (delayHistory) await new Promise(resolve => setTimeout(resolve, 600));
      } else if (/\/revisions\/1$/.test(url.pathname)) data = { revision: 1, createdAt: '2026-10-09T10:00:00Z', adminId: 500, article: old };
      else if (url.pathname.endsWith('/restore')) {
        restores++; lastRestore = req.postDataJSON();
        if (restoreConflict) return route.fulfill({ status: 409, json: { message: 'conflict' } });
        Object.assign(article, old, { revision: article.revision + 1, status: article.status, allowComments: article.allowComments });
        data = article;
      } else if (/\/admin\/articles\/(hotspot|analysis)\/source-diff$/.test(url.pathname)) data = { items: [{ slug: 'missing', title: '来源缺失项', articleId: null, sourceStatus: 'source-only' }], total: 1, page: 1, perPage: 10, counts: { same: 0, different: 0, databaseOnly: 0, sourceOnly: 1 } };
      else if (url.pathname.endsWith('/source-diff')) {
        data = { revision: article.revision, sourceStatus: 'different', maintained: true, fields: [{ field: 'title', databaseValue: article.title, sourceValue: '源标题', equal: false }, { field: 'html', databaseValue: article.html, sourceValue: '<img src=x onerror="window.pwned=1"><script>window.pwned=1</script><p>来源正文</p>', equal: false }] };
        if (sourceGate) { const pending = sourceGate; sourceGate = null; pending.started(); await pending.wait; }
      }
      else if (url.pathname.endsWith('/export')) data = { format: 'markdown', body: raw, title: '原文', revision: Number(url.searchParams.get('revision') || article.revision), fileName: '../../CON.md' };
      else if (/\/admin\/(hotspots|analysis)\/1$/.test(url.pathname)) {
        if (req.method() === 'PATCH') { patches++; lastPatch = req.postDataJSON(); Object.assign(article, lastPatch, { revision: article.revision + 1 }); }
        data = article;
      } else if (/\/admin\/(hotspots|analysis)$/.test(url.pathname)) data = { items: [article], total: 1, page: 1, perPage: 20, filters: { periods: [], categories: [] } };
      await route.fulfill({ json: { data } });
    });
    const page = await context.newPage(), errors = [];
    page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(60000);
    page.on('pageerror', error => errors.push(error.message));
    const title = page.getByLabel('文章标题'), body = page.getByLabel('文章正文', { exact: true });
    const history = page.getByRole('region', { name: '文章修订与来源' });
    if (!process.env.ADMIN_HISTORY_RACE_ONLY) {
    await page.goto(`${base}/admin/hotspots`, { waitUntil: 'domcontentloaded' });
    await page.locator('.record-list li').getByRole('button', { name: '编辑', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[aria-label="文章正文"]').value === '当前正文');
    await page.getByRole('button', { name: '查看修订历史', exact: true }).click();
    await page.getByRole('button', { name: '历史下一页' }).click();
    await page.getByRole('button', { name: '查看修订 1', exact: true }).click();
    await page.getByRole('heading', { name: '只读修订 1' }).waitFor();
    assert.ok(revisionPages.includes(2)); assert.equal(await body.inputValue(), '当前正文');
    assert.equal(await history.locator('script,[onerror]').count(), 0);
    const historicalDownload = page.waitForEvent('download');
    await page.getByRole('button', { name: '导出所选修订原文' }).click();
    assert.equal(await fs.readFile(await (await historicalDownload).path(), 'utf8'), raw);
    await title.fill('未保存草稿');
    page.once('dialog', d => d.dismiss()); await page.getByRole('button', { name: '恢复所选修订' }).click();
    assert.equal(restores, 0); assert.equal(await title.inputValue(), '未保存草稿');
    restoreConflict = true;
    const accept = d => d.accept(); page.on('dialog', accept);
    await page.getByRole('button', { name: '恢复所选修订' }).click();
    await history.getByRole('alert').filter({ hasText: '当前编辑输入已保留' }).waitFor();
    assert.equal(await title.inputValue(), '未保存草稿'); assert.equal(await body.inputValue(), '当前正文');
    assert.deepEqual(lastRestore, { revision: 1, expectedRevision: 12 });
    restoreConflict = false;
    await page.getByRole('button', { name: '恢复所选修订' }).click();
    await page.waitForFunction(() => document.querySelector('[aria-label="文章标题"]').value === '旧文章');
    assert.equal(await body.inputValue(), raw); assert.equal(await page.getByLabel('文章状态').inputValue(), 'hidden'); assert.equal(article.allowComments, false); assert.equal(article.revision, 13);
    await page.getByText(/无未保存修改 · 修订 13/).waitFor();
    await history.getByText('第 1 页 · 共 13 条', { exact: true }).waitFor();
    await page.waitForFunction(() => !document.querySelector('.article-history').__vueParentComponent.setupState.blocked);
    const diffButton = page.getByRole('button', { name: '读取来源差异' });
    await diffButton.scrollIntoViewIfNeeded();
    await diffButton.evaluate(async button => { await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))); const b = button.getBoundingClientRect(); if (button.disabled || document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2) !== button) throw new Error('Diff button geometry not ready'); });
    await diffButton.click();
    await page.getByRole('heading', { name: '来源对账 · 数据库与来源存在差异' }).waitFor().catch(async error => { console.error(await history.innerText()); throw error; });
    assert.equal(await history.locator('script,[onerror]').count(), 0); assert.equal(await page.evaluate(() => window.pwned), undefined);
    await page.getByRole('button', { name: '查看数据集对账总览' }).click();
    await history.getByText('来源缺失项 · missing · 仅来源（数据库缺失）').waitFor();
    await page.getByLabel('采用来源字段 title').check(); await page.getByLabel('采用来源字段 html').check();
    await page.getByRole('button', { name: '将所选源字段放入草稿' }).click();
    assert.equal(await title.inputValue(), '源标题'); assert.equal(await page.getByLabel('正文格式').inputValue(), 'html'); assert.ok((await body.inputValue()).includes('<script>')); assert.equal(patches, 0); assert.equal(article.revision, 13);
    await page.getByText(/有未保存修改 · 修订 13/).waitFor();
    await page.getByRole('button', { name: '保存文章', exact: true }).click(); await page.getByText(/无未保存修改 · 修订 14/).waitFor();
    assert.equal(lastPatch.expectedRevision, 13); assert.equal(lastPatch.format, 'html');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: '导出当前已保存原文' }).click(); const download = await downloadPromise;
    assert.equal(await fs.readFile(await download.path(), 'utf8'), raw); assert.ok(!/[\\/<>:"|?*]/.test(download.suggestedFilename())); assert.ok(download.suggestedFilename().endsWith('.md'));
    // Closing a component invalidates in-flight list responses.
    delayHistory = true;
    await page.getByRole('button', { name: '刷新修订历史' }).click();
    await page.getByRole('button', { name: '关闭编辑器' }).click();
    await page.getByRole('region', { name: '文章编辑器' }).waitFor({ state: 'detached' });
    await new Promise(resolve => setTimeout(resolve, 800));
    } else { article.revision = 14; article.title = '源标题'; }
    delayHistory = false;
    await page.goto(`${base}/admin/analysis`, { waitUntil: 'domcontentloaded' });
    await page.locator('.record-list li').getByRole('button', { name: '编辑', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[aria-label="文章标题"]').value === '源标题');
    await page.getByRole('button', { name: '读取来源差异' }).click();
    await history.getByRole('heading', { name: '来源对账 · 数据库与来源存在差异' }).waitFor();
    assert.equal(await history.locator('script,[onerror]').count(), 0);
    // A source response captured before a save must not reinstall the old comparison.
    await page.getByRole('button', { name: '查看修订历史', exact: true }).click();
    await page.getByRole('button', { name: '查看修订 14', exact: true }).waitFor();
    const staleSource = gate(); sourceGate = staleSource;
    await page.getByRole('button', { name: '读取来源差异' }).click(); await staleSource.begun;
    await title.fill('来源读取期间保存'); await page.getByRole('button', { name: '保存文章', exact: true }).click();
    await page.getByText(/无未保存修改 · 修订 15/).waitFor();
    await page.getByRole('button', { name: '查看修订 15', exact: true }).waitFor();
    const staleSourceFinished = page.waitForResponse(r => r.url().endsWith('/analysis/1/source-diff'));
    staleSource.release(); await staleSourceFinished;
    await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
    assert.equal(await history.locator('.source-diff').count(), 0);
    // An old history page arriving after the replacement page cannot remove revision 16.
    const staleHistory = gate(); historyGate = staleHistory;
    await page.getByRole('button', { name: '刷新修订历史' }).click(); await staleHistory.begun;
    await title.fill('历史读取期间保存'); await page.getByRole('button', { name: '保存文章', exact: true }).click();
    await page.getByText(/无未保存修改 · 修订 16/).waitFor();
    await page.getByRole('button', { name: '查看修订 16', exact: true }).waitFor();
    const staleHistoryFinished = page.waitForResponse(r => r.url().includes('/analysis/1/revisions?'));
    staleHistory.release(); await staleHistoryFinished;
    await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
    assert.equal(await page.getByRole('button', { name: '查看修订 16', exact: true }).count(), 1);
    assert.deepEqual(errors, []);
    console.log('PASS delayed source/history reads across saves, latest history reload, postrestore mouse activation, revision pagination, readonly selection, dirty cancellation, conflict input retention, restore creates revision and preserves status/comments, safe source diff, missing source overview, explicit source draft adoption/save, raw editable download and stale response isolation');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

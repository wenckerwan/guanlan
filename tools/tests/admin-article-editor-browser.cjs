const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const base = process.env.ADMIN_BROWSER_BASE || 'http://127.0.0.1:3209';
    const context = await browser.newContext();
    const admin = { id: 500, email: 'admin@example.test', displayName: 'Admin', role: 'admin', status: 'active', userGroup: 'user' };
    await context.addCookies([{ name: 'guanlan.token', value: 'test-token', url: base }]);
    await context.addInitScript(user => { if (window === window.top) localStorage.setItem('guanlan.user', JSON.stringify(user)); }, admin);
    const original = '<h2 id="original">原文</h2>\n<p class="original" style="color:red">保留原样 &amp; 空格</p>';
    const article = id => ({ id, slug: `article-${id}`, title: `文章${id}`, summary: '原摘要', status: 'published', period: '2026年10月', category: '材料分析', priority: 'A', level: 'S', type: '形势与政策', tag: '政策', format: 'html', body: original, html: original, outline: [{ id: 'original', title: '原文', level: 2 }], wordCount: 8, revision: 1, contentSource: 'dataset' });
    const articles = { hotspots: [article(1), article(2)], analysis: [article(1), article(2)] };
    let conflict = false, invalidPreview = false, previewFailure = false, saveFailure = false, detailFailure = false, detailDelay = false;
    let saveCount = 0, previewCount = 0, lastPayload;
    const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
    const render = input => ({ html: input.format === 'html' ? input.body : `<h2>Markdown</h2><p>${input.body.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))}</p>`, outline: [{ id: 'heading', title: '标题', level: 2 }], wordCount: input.body.length });
    await context.route('**/api/v1/**', async route => {
      const req = route.request(), url = new URL(req.url()); let data = [];
      if (url.pathname.endsWith('/auth/me')) data = { user: admin };
      else if (url.pathname.endsWith('/admin/articles/preview')) {
        previewCount++;
        const input = req.postDataJSON();
        if (previewFailure) return route.fulfill({ status: 500, json: { message: '预览模拟失败' } });
        if (invalidPreview) data = { html: 7, outline: [], wordCount: 5 };
        else data = render(input);
        if (input.body === 'slow') await delay(600);
      } else if (/\/admin\/(hotspots|analysis)(\/\d+)?$/.test(url.pathname)) {
        const [, kind, id] = url.pathname.match(/\/admin\/(hotspots|analysis)(?:\/(\d+))?$/);
        if (req.method() === 'GET' && id) {
          if (detailFailure) return route.fulfill({ status: 500, json: { message: '详情模拟失败' } });
          data = { ...articles[kind].find(item => item.id === Number(id)) };
          if (detailDelay && Number(id) === 1) await delay(650);
        } else if (req.method() === 'GET') data = { items: articles[kind], total: articles[kind].length, page: 1, perPage: 20, filters: { periods: ['2026年10月'], categories: ['材料分析'] } };
        else {
          saveCount++; lastPayload = req.postDataJSON();
          if (conflict) return route.fulfill({ status: 409, json: { message: 'stale revision' } });
          if (saveFailure) return route.fulfill({ status: 500, json: { message: '保存模拟失败' } });
          await delay(400);
          const writesBody = Object.hasOwn(lastPayload, 'body') || Object.hasOwn(lastPayload, 'format');
          const persisted = id ? articles[kind].find(item => item.id === Number(id)) : article(100 + saveCount);
          const rendered = writesBody ? render(lastPayload) : {};
          if (writesBody && lastPayload.format === 'html') rendered.html = rendered.html.replace(/ (?:class|style|id)="[^"]*"/g, '');
          const content = writesBody ? { ...rendered, body: lastPayload.format === 'html' ? rendered.html : lastPayload.body } : {};
          data = { ...persisted, ...lastPayload, ...content, revision: (lastPayload.expectedRevision ?? 0) + 1, contentSource: 'admin' };
          if (id) Object.assign(articles[kind].find(item => item.id === Number(id)), data);
          else articles[kind].push(data);
        }
      }
      await route.fulfill({ json: { data } });
    });
    const page = await context.newPage(), errors = [];
    page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(60000);
    page.on('pageerror', error => errors.push(error.message));
    const editor = page.getByRole('region', { name: '文章编辑器' });
    const body = () => page.getByLabel('文章正文', { exact: true });
    const row = id => page.locator('.record-list li').filter({ hasText: `文章${id}` }).first();
    const edit = id => row(id).getByRole('button', { name: '编辑', exact: true }).click();
    const close = () => page.getByRole('button', { name: '关闭编辑器', exact: true }).click();
    const preview = () => page.getByRole('button', { name: /^(预览正文|更新预览…)$/ }).click();
    const save = () => page.getByRole('button', { name: '保存文章', exact: true }).click();
    for (const kind of ['hotspots', 'analysis']) {
      await page.goto(`${base}/admin/${kind}`); await row(1).waitFor();
      await page.getByRole('button', { name: kind === 'hotspots' ? '新增热点' : '新增分析', exact: true }).click();
      await page.getByLabel('文章标题').fill('新文章'); await page.getByLabel('文章摘要').fill('新摘要');
      await body().fill('# 标题\n正文 <script>window.pwned=1</script>');
      await preview(); await page.getByText(/正文预览 · \d+ 字/).waitFor();
      const frame = page.frameLocator('iframe[title="安全正文预览"]');
      await frame.getByText(/正文 <script>/).waitFor(); assert.equal(await frame.locator('script').count(), 0);
      assert.equal(await page.evaluate(() => window.pwned), undefined);
      const count = saveCount; await save(); await page.getByRole('button', { name: '保存中…' }).waitFor();
      assert.equal(await page.getByLabel('文章标题').isDisabled(), true);
      await page.evaluate(() => { const form = document.querySelector('.article-editor form'); form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); });
      await page.getByText('已保存文章', { exact: true }).waitFor(); await editor.waitFor({ state: 'detached' }); assert.equal(saveCount, count + 1);
      assert.equal(lastPayload.format, 'markdown'); assert.equal(lastPayload.body.includes('<script>'), true);
      await edit(1); await body().waitFor(); await page.waitForFunction(() => document.querySelector('[aria-label="文章正文"]').value.includes('保留原样'));
      assert.equal(await body().inputValue(), original); assert.equal(await page.getByLabel('正文格式').inputValue(), 'html');
      await page.getByLabel('正文格式').selectOption('markdown'); assert.equal(await body().inputValue(), '');
      await body().fill('独立草稿'); await page.getByLabel('正文格式').selectOption('html'); assert.equal(await body().inputValue(), original);
      await page.getByLabel('文章摘要').fill('仅改元数据'); await save(); await editor.getByText('已保存', { exact: true }).waitFor();
      assert.equal(Object.hasOwn(lastPayload, 'body'), false); assert.equal(Object.hasOwn(lastPayload, 'format'), false); assert.equal(lastPayload.expectedRevision, 1);
      assert.equal(articles[kind][0].body, original); assert.equal(articles[kind][0].html, original);
      await page.getByText(/正文预览 · \d+ 字/).waitFor(); await close();
      await edit(2); await page.waitForFunction(() => document.querySelector('[aria-label="文章正文"]').value.includes('保留原样'));
      const changed = '<p class="edited" style="color:red" id="edited">修改正文</p>';
      await body().fill(changed); await save(); await editor.getByText('已保存', { exact: true }).waitFor();
      assert.equal(lastPayload.body, changed); assert.equal(lastPayload.format, 'html'); assert.equal(await body().inputValue(), '<p>修改正文</p>');
      await page.getByLabel('文章摘要').fill('正文保存后只改摘要'); await save(); await editor.getByText('已保存', { exact: true }).waitFor();
      assert.equal(Object.hasOwn(lastPayload, 'body'), false); assert.equal(Object.hasOwn(lastPayload, 'format'), false); assert.equal(lastPayload.expectedRevision, 2); await close();
      console.log(`PASS ${kind}: create, safe Markdown preview, save locks, HTML fidelity, independent format drafts, metadata edit`);
    }
    await edit(1); await page.waitForFunction(() => document.querySelector('[aria-label="文章正文"]').value.includes('保留原样'));
    await page.getByLabel('文章标题').fill('我的草稿'); conflict = true; await save(); await page.getByRole('alert').filter({ hasText: '你的输入已保留' }).waitFor();
    assert.equal(await page.getByLabel('文章标题').inputValue(), '我的草稿');
    articles.analysis[0].revision = 9; articles.analysis[0].title = '最新版本标题';
    articles.analysis[0].body = articles.analysis[0].html = original + '<p class="latest" style="color:red">最新正文</p>';
    await page.getByRole('button', { name: '读取最新版本（保留草稿）' }).click(); await page.getByText('最新版本 9：最新版本标题').waitFor();
    assert.equal(await page.getByLabel('文章标题').inputValue(), '我的草稿');
    page.once('dialog', dialog => dialog.accept()); await page.getByRole('button', { name: '采用最新版本', exact: true }).click(); conflict = false;
    await page.getByLabel('文章标题').fill('冲突处理后'); await save(); await editor.getByText('已保存', { exact: true }).waitFor(); assert.equal(lastPayload.expectedRevision, 9);
    assert.equal(Object.hasOwn(lastPayload, 'body'), false); assert.equal(Object.hasOwn(lastPayload, 'format'), false);
    assert.equal(articles.analysis[0].html, original + '<p class="latest" style="color:red">最新正文</p>');
    await body().fill('保留失败输入'); saveFailure = true; await save(); await page.getByRole('alert').filter({ hasText: '保存模拟失败' }).waitFor(); assert.equal(await body().inputValue(), '保留失败输入');
    saveFailure = false; await save(); await editor.getByText('已保存', { exact: true }).waitFor();
    invalidPreview = true; await preview(); await page.getByRole('alert').filter({ hasText: '响应格式无效' }).waitFor(); invalidPreview = false;
    previewFailure = true; await preview(); await page.getByRole('alert').filter({ hasText: '预览模拟失败' }).waitFor(); previewFailure = false;
    await body().fill('<img src=x onerror="window.pwned=1"><script>window.pwned=1</script><p>安全</p>'); await preview(); await page.frameLocator('iframe').getByText('安全', { exact: true }).waitFor(); assert.equal(await page.frameLocator('iframe').locator('script,[onerror]').count(), 0); assert.equal(await page.evaluate(() => window.pwned), undefined);
    await body().fill('slow'); await preview(); await body().fill('fast'); await preview();
    await page.getByText('正文预览 · 4 字 · 1 个标题').waitFor(); await delay(800);
    assert.equal(await page.frameLocator('iframe').locator('body').innerText(), 'fast');
    console.log('PASS conflict retains drafts, explicit latest adoption, failure retry, validation and latest preview wins');
    page.once('dialog', dialog => dialog.dismiss()); await close(); assert.equal(await editor.count(), 1);
    let leaveDialogs = 0; const dismiss = dialog => { leaveDialogs++; dialog.dismiss(); }; page.on('dialog', dismiss);
    await page.evaluate(() => document.querySelector('.admin-section').__vueParentComponent.proxy.$router.push('/admin/hotspots')); await delay(200); assert.equal(new URL(page.url()).pathname, '/admin/analysis');
    page.removeListener('dialog', dismiss); assert.equal(leaveDialogs, 1);
    assert.equal(await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; }), true);
    page.once('dialog', dialog => dialog.accept()); await close(); await editor.waitFor({ state: 'detached' });
    detailFailure = true; await edit(2); await page.getByRole('alert').filter({ hasText: '详情模拟失败' }).waitFor(); detailFailure = false;
    await page.getByRole('button', { name: '重试读取' }).click(); await page.waitForFunction(() => document.querySelector('[aria-label="文章标题"]').value === '文章2'); await close();
    // A slow obsolete detail must never overwrite another selected article.
    detailDelay = true;
    await page.evaluate(() => document.querySelector('.admin-section').__vueParentComponent.proxy.$router.push('/admin/hotspots')); await row(1).waitFor();
    await edit(1); await edit(2); await page.waitForFunction(() => document.querySelector('[aria-label="文章标题"]').value === '文章2'); await delay(800);
    assert.equal(await page.getByLabel('文章标题').inputValue(), '文章2');
    assert.deepEqual(errors, []); assert.ok(previewCount >= 7);
    console.log('PASS unsaved close, route, beforeunload, detail retry and obsolete detail isolation; no page errors');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

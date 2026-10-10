const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
 const browser = await chromium.launch({ channel: 'msedge', headless: true });
 try {
  const base = process.env.ADMIN_BROWSER_BASE || 'http://127.0.0.1:3210', context = await browser.newContext();
  const admin = { id: 500, email: 'admin@example.test', displayName: 'Admin', role: 'admin', status: 'active', userGroup: 'user' };
  await context.addCookies([{ name: 'guanlan.token', value: 'test-token', url: base }]);
  await context.addInitScript(user => localStorage.setItem('guanlan.user', JSON.stringify(user)), admin);
  await context.route(base + '/admin/**', route => { const headers = { ...route.request().headers() }; delete headers.cookie; return route.continue({ headers }); });
  const mocks = Array.from({ length: 21 }, (_, i) => ({ slug: `mock-${i + 1}`, title: `模拟卷${i + 1}`, summary: '<b>原始摘要</b>', questionCount: i === 0 ? 121 : 2, totalScore: 100, durationMinutes: 180, answeredCount: 0 }));
  const predictions = Array.from({ length: 41 }, (_, i) => ({ id: i + 1, slug: `prediction-${i + 1}`, title: `预测${i + 1}`, layer: 'A', wordCount: 500, sourceFile: 'source.md', status: 'published', commentMode: 'open', sortOrder: i + 1 }));
  let detailFailure = 0, listFailure = 0, delayedDetail = false, predictionFailure = 0, patchFailure = false, patchCount = 0, actionDelay = 0, reloadDelay = 0, predictionDelay = false, reads = [], bodies = [];
  const envelope = (items, q) => { const perPage = Math.min(100, Number(q.get('perPage') || 20)), page = Math.max(1, Math.min(Number(q.get('page') || 1), Math.ceil(items.length / perPage) || 1)); return { items: items.slice((page - 1) * perPage, page * perPage), total: items.length, page, perPage }; };
  await context.route('**/api/v1/**', async route => {
   const url = new URL(route.request().url()), q = url.searchParams; reads.push(url); let data = [];
   if (url.pathname.endsWith('/auth/me')) data = { user: admin };
   else if (/\/admin\/mocks\/[^/]+$/.test(url.pathname)) {
    const slug = decodeURIComponent(url.pathname.split('/').pop()), mock = mocks.find(item => item.slug === slug);
    if (!mock || detailFailure) return route.fulfill({ status: detailFailure || 404, json: { message: !mock ? '模拟卷不存在' : '模拟卷详情失败' } });
    if (slug === 'mock-1' && delayedDetail) await new Promise(resolve => setTimeout(resolve, 850));
    data = { mock: { ...mock, sourceFile: `dataset/${slug}.json` }, questions: envelope(Array.from({ length: mock.questionCount }, (_, i) => ({ no: i + 1, type: 'single', typeCn: '单选', score: 2, module: 'm', moduleName: '马原', kaodian: '知识点', stem: `${slug}题目${i + 1}\n<img src=x onerror="window.__mockXss=1">`, options: { A: '<b>选项A</b>', H: '选项H' }, answer: 'H', analysis: '<script>原始解析</script>' })), q) };
   } else if (url.pathname.endsWith('/admin/mocks')) {
    if (listFailure) return route.fulfill({ status: listFailure, json: { message: '模拟列表失败' } });
    data = envelope(mocks, q);
   } else if (/\/admin\/predictions\/\d+$/.test(url.pathname)) {
    patchCount++; const body = route.request().postDataJSON(); bodies.push(body);
    if (actionDelay) await new Promise(resolve => setTimeout(resolve, actionDelay));
    if (patchFailure) return route.fulfill({ status: 500, json: { message: '排序保存失败' } });
    const row = predictions.find(item => item.id === Number(url.pathname.split('/').pop())); Object.assign(row, body); data = row;
   } else if (url.pathname.endsWith('/admin/comments/mode')) {
    const body = route.request().postDataJSON(); bodies.push(body); const row = predictions.find(item => item.slug === body.slug); row.commentMode = body.mode; data = {};
   } else if (url.pathname.endsWith('/admin/predictions')) {
    if (predictionFailure) return route.fulfill({ status: predictionFailure, json: { message: '预测列表失败' } });
    if (reloadDelay) await new Promise(resolve => setTimeout(resolve, reloadDelay));
    if (q.get('page') === '2' && predictionDelay) await new Promise(resolve => setTimeout(resolve, 800));
    data = envelope([...predictions].sort((a, b) => a.sortOrder - b.sortOrder || a.id - b.id), q);
   }
   await route.fulfill({ json: { data } });
  });
  const page = await context.newPage(), errors = []; page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(60000); page.on('pageerror', error => errors.push(error.message));
  if (process.env.LIBRARY_RED === 'predictions') {
   await page.goto(`${base}/admin/predictions`); await page.getByRole('heading', { name: '共 41 篇', exact: true }).waitFor(); await page.getByLabel('预测排序 #1', { exact: true }).waitFor(); return;
  }
  if (process.env.LIBRARY_ONLY !== 'predictions') {
  await page.goto(`${base}/admin/mocks?mock=mock-1&mockPage=999&page=2`);
  await page.getByRole('heading', { name: '模拟卷详情：模拟卷1', exact: true }).waitFor();
  await page.waitForURL(url => url.searchParams.get('mockPage') === '7');
  const detail = page.locator('.mock-detail'); await detail.getByText(/mock-1题目121/).waitFor(); await detail.getByText(/来源文件：dataset\/mock-1.json/).waitFor(); await detail.getByText('<b>原始摘要</b>', { exact: true }).waitFor(); assert.equal(await detail.locator('.mock-question').count(), 1);
  assert.equal(await detail.locator('img, script').count(), 0); await detail.getByText('H. 选项H', { exact: true }).waitFor(); await detail.getByText('<script>原始解析</script>', { exact: true }).waitFor();
  await page.reload(); await page.getByRole('heading', { name: '模拟卷详情：模拟卷1', exact: true }).waitFor(); await detail.getByText(/mock-1题目121/).waitFor();
  assert.equal(await page.locator('.mock-list tbody tr').count(), 1);
  await detail.getByRole('button', { name: '上一页', exact: true }).focus(); await page.keyboard.press('Enter'); await detail.getByText(/mock-1题目101/).waitFor();
  await page.locator('.mock-list').getByRole('button', { name: '上一页', exact: true }).click(); await page.getByRole('button', { name: '查看题目', exact: true }).first().waitFor();
  delayedDetail = true; await page.locator('.mock-list tbody tr').first().getByRole('button', { name: '查看题目', exact: true }).click();
  await page.locator('.mock-list tbody tr').nth(1).getByRole('button', { name: '查看题目', exact: true }).click(); await page.getByRole('heading', { name: '模拟卷详情：模拟卷2', exact: true }).waitFor(); await page.waitForTimeout(1000); assert.equal(await detail.locator('.mock-question').count(), 2); assert.equal(await detail.getByText(/mock-1题目/).count(), 0); delayedDetail = false;
  detailFailure = 500; await detail.getByRole('button', { name: '刷新详情', exact: true }).click(); await detail.getByRole('alert').waitFor(); assert.equal(await detail.locator('.mock-question').count(), 0); detailFailure = 0; await detail.getByRole('button', { name: '重试详情', exact: true }).click(); await detail.getByText(/mock-2题目1/).waitFor();
  await page.goto(`${base}/admin/mocks?mock=missing`); await detail.getByRole('alert').waitFor(); await detail.getByText('模拟卷不存在', { exact: true }).waitFor();
  listFailure = 500; await page.goto(`${base}/admin/mocks?mock=mock-2`); await page.locator('.mock-list').getByRole('alert').waitFor(); await detail.getByText(/mock-2题目1/).waitFor(); listFailure = 0; await page.locator('.mock-list').getByRole('button', { name: '重试', exact: true }).click(); await page.getByRole('heading', { name: '模拟押题（21 套）', exact: true }).waitFor();
  await page.setViewportSize({ width: 390, height: 844 }); assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  console.log('PASS mock121-question detail, source/plaintext/letter options, independent list+detail URLs/canonical paging/refresh/errors/retries/404/stale isolation/mobile');
  }
  await page.setViewportSize({ width: 1280, height: 900 }); await page.goto(`${base}/admin/predictions?page=999`); await page.getByRole('heading', { name: '共 41 篇', exact: true }).waitFor(); await page.waitForURL(url => url.searchParams.get('page') === '3'); await page.getByLabel('预测排序 #41', { exact: true }).waitFor();
  await page.reload(); await page.getByLabel('预测排序 #41', { exact: true }).waitFor();
  await page.getByLabel('预测排序 #41', { exact: true }).fill('1.5'); await page.getByRole('button', { name: '保存排序', exact: true }).click(); await page.getByRole('alert').waitFor(); assert.equal(patchCount, 0);
  await page.getByLabel('预测排序 #41', { exact: true }).fill('2147483648'); await page.getByRole('button', { name: '保存排序', exact: true }).click(); assert.equal(patchCount, 0);
  await page.getByLabel('预测排序 #41', { exact: true }).fill('-2147483648'); actionDelay = 350; reloadDelay = 350; await page.getByRole('button', { name: '保存排序', exact: true }).click(); assert.equal(await page.locator('tbody button:not([disabled]),tbody select:not([disabled])').count(), 0); await page.getByRole('status').filter({ hasText: '排序已保存' }).waitFor(); assert.deepEqual(bodies.at(-1), { sortOrder: -2147483648 }); assert.equal(predictions[40].status, 'published'); assert.equal(predictions[40].commentMode, 'open'); actionDelay = 0; reloadDelay = 0;
  await page.getByRole('button', { name: '上一页', exact: true }).click(); await page.getByRole('button', { name: '上一页', exact: true }).click(); await page.getByLabel('预测排序 #41', { exact: true }).waitFor();
  await page.getByLabel('预测排序 #1', { exact: true }).fill('77');
  const first = page.locator('tbody tr').first(); patchFailure = true; await first.getByLabel('预测排序 #41', { exact: true }).fill('5'); await first.getByRole('button', { name: '保存排序', exact: true }).click(); await page.getByRole('alert').waitFor(); assert.equal(await first.getByLabel('预测排序 #41', { exact: true }).inputValue(), '5'); patchFailure = false; predictions[1].sortOrder = 3; await first.getByRole('button', { name: '保存排序', exact: true }).click(); await page.getByRole('status').filter({ hasText: '排序已保存' }).waitFor();
  assert.equal(await page.getByLabel('预测排序 #1', { exact: true }).inputValue(), '77', 'saving another row must preserve unsaved sort draft');
  assert.equal(await page.getByLabel('预测排序 #2', { exact: true }).inputValue(), '3', 'untouched sort draft should refresh from confirmed server value');
  assert.equal(await page.getByLabel('预测排序 #41', { exact: true }).inputValue(), '5', 'saved row should sync to confirmed sort');
  const published = page.locator('tbody tr').first(); await published.getByRole('button', { name: '已发布', exact: true }).click(); await page.getByRole('status').filter({ hasText: '已隐藏' }).waitFor();
  await page.locator('tbody tr').first().getByLabel(/评论模式/).selectOption('review'); await page.getByRole('status').filter({ hasText: '评论设置已更新' }).waitFor();
  predictionFailure = 500; await page.getByRole('button', { name: '刷新列表', exact: true }).click(); await page.getByRole('alert').waitFor(); assert.equal(await page.locator('tbody tr').count(), 0); assert.equal(await page.getByText('暂无预测内容。', { exact: true }).count(), 0); predictionFailure = 0; await page.getByRole('button', { name: '重试列表', exact: true }).click(); await page.getByRole('heading', { name: '共 41 篇', exact: true }).waitFor();
  predictionDelay = true; await page.getByRole('button', { name: '下一页', exact: true }).click(); await page.waitForURL(url => url.searchParams.get('page') === '2'); await page.goBack(); await page.getByLabel('预测排序 #1', { exact: true }).waitFor(); await page.waitForTimeout(1000); assert.equal(await page.locator('tbody tr').first().locator('td').first().innerText(), '预测1'); predictionDelay = false;
  await page.setViewportSize({ width: 390, height: 844 }); assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)); assert.deepEqual(errors, []);
  console.log('PASS predictions canonical URL/refresh, integer sort+range validation/preservation/explicit save/error draft/retry/mutation locks/status+comments/list failure/mobile');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

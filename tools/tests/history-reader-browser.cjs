// Run against a local production preview; content is intercepted with the checked-in dataset.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'D:/code_files/考研政治工程文件/近现代史时间轴/node_modules/playwright');
const root = path.resolve(__dirname, '../..');
const dataset = JSON.parse(fs.readFileSync(path.join(root, 'storage/history/history.v1.json'), 'utf8'));
const output = path.join(root, 'output/history-reader');
fs.mkdirSync(output, { recursive: true });

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/v1/history/events', route => route.fulfill({ json: { data: dataset } }));
    await page.goto(`${process.argv[2] || 'http://localhost:3000'}/history/`);
    await page.waitForSelector('.hr-reader');
    const lessons = page.locator('.hr-lesson');
    const total = await lessons.count();
    assert.equal(total, 103);
    assert.equal(await page.locator('.hr-masthead').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(23, 72, 117)');
    await page.screenshot({ path: path.join(output, 'desktop.png') });

    const search = page.getByRole('searchbox');
    await search.fill('虎门');
    await page.waitForFunction(() => document.querySelectorAll('.hr-lesson').length === 1);
    await page.locator('.hr-table-scroll a').first().click();
    await page.waitForSelector('.hr-lesson[open]');
    const eventId = await lessons.first().getAttribute('id');
    await page.getByRole('button', { name: '收藏事件', exact: true }).click();
    await page.waitForSelector('.hr-actions button[aria-pressed="true"]');
    const note = page.locator('.hr-note textarea');
    await note.fill('回忆线索：1839，虎门销烟'); await note.blur();
    await page.waitForFunction(() => document.querySelector('.hr-note small')?.textContent?.includes('已保存在本机'));
    await page.getByRole('button', { name: '速记复习', exact: true }).click();
    assert.equal(await page.locator('.hr-full-only').first().isVisible(), false);
    await page.getByRole('button', { name: '完整阅读', exact: true }).click();
    assert.equal(await note.inputValue(), '回忆线索：1839，虎门销烟');
    await page.reload(); await page.waitForSelector('.hr-reader');
    await page.waitForSelector('.hr-lesson[open]');
    assert.equal(await page.locator('.hr-note textarea').inputValue(), '回忆线索：1839，虎门销烟');
    assert.equal(await page.locator('.hr-actions button').first().getAttribute('aria-pressed'), 'true');

    await page.getByRole('button', { name: '清除筛选', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('.hr-lesson').length === 103);
    const selects = page.locator('.hr-toolbar select');
    await selects.nth(0).selectOption('1');
    assert.ok(await lessons.count() > 0 && await lessons.count() < total);
    await selects.nth(1).selectOption('war');
    const filteredTitles = await page.locator('.hr-lesson summary strong').allTextContents();
    assert.ok(filteredTitles.length > 0);
    assert.ok(filteredTitles.every(title => dataset.events.some(e => e.title === title && e.topicId === 'war' && !e.isGroup && e.publication !== 'pending')));
    await search.fill('不存在的事件XYZ');
    await page.waitForSelector('.hr-tool-panel .hr-empty');
    assert.equal(await lessons.count(), 0);
    await page.getByRole('button', { name: '清除筛选', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('.hr-lesson').length === 103);
    await page.getByRole('checkbox', { name: '查看原表合并组' }).check();
    assert.equal(await lessons.count(), dataset.events.filter(e => e.isGroup && e.publication !== 'pending').length);
    await page.getByRole('checkbox', { name: '查看原表合并组' }).uncheck();

    await page.getByRole('button', { name: '展开筛选结果', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('.hr-lesson[open]').length === 103);
    await page.getByRole('button', { name: '收起全部', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('.hr-lesson[open]').length === 0);
    await lessons.nth(0).locator('summary').click();
    await lessons.nth(0).getByRole('button', { name: '加入比较', exact: true }).click();
    await lessons.nth(1).locator('summary').click();
    await lessons.nth(1).getByRole('button', { name: '加入比较', exact: true }).click();
    await page.locator('.hr-nav button').nth(1).click();
    await page.waitForSelector('.ha-comparison-grid article');
    assert.equal(await page.locator('.ha-comparison-grid article').count(), 2);
    await page.getByRole('button', { name: '事件目录与阅读', exact: true }).click();
    await page.waitForSelector('.hr-reader');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => window.scrollTo(0, 0));
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await page.screenshot({ path: path.join(output, 'mobile.png') });
    await page.locator('.hr-table-scroll a').first().click();
    await page.waitForSelector('.hr-lesson[open]');
    await page.keyboard.press('Escape');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);

    await page.getByRole('button', { name: '收起全部', exact: true }).click();
    await page.getByRole('button', { name: '速记复习', exact: true }).click();
    const openBefore = await page.locator('.hr-lesson[open]').count();
    await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
    await page.waitForFunction(() => document.querySelectorAll('.hr-lesson[open]').length === 103);
    await page.emulateMedia({ media: 'print' });
    assert.equal(await page.locator('.hr-lesson-body').last().isVisible(), true);
    await page.pdf({ path: path.join(output, 'print.pdf'), format: 'A4', printBackground: false });
    await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
    await page.emulateMedia({ media: 'screen' });
    await page.waitForFunction(count => document.querySelectorAll('.hr-lesson[open]').length === count, openBefore);
    assert.equal(await page.getByRole('button', { name: '速记复习', exact: true }).getAttribute('aria-pressed'), 'true');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ events: total, search: true, filters: true, emptyState: true, notesRestored: true, bookmarksRestored: true, comparison: true, mobileOverflow: false, printRestore: true, pageErrors: errors, screenshotDirectory: output, selectedEvent: eventId }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });

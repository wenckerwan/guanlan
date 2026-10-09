const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext();
    const base = process.env.ADMIN_BROWSER_BASE || 'http://127.0.0.1:3209';
    const user = { id: 1, email: 'admin@example.test', displayName: 'Test Admin', role: 'admin', status: 'active', userGroup: 'user', mistakeCode: '' };
    await context.addCookies([{ name: 'guanlan.token', value: 'browser-test-token', url: base }]);
    await context.addInitScript(user => localStorage.setItem('guanlan.user', JSON.stringify(user)), user);
    let deleted = 0, changed = 0;
    let articles = [{ id: 1, slug: 'test', title: 'Test article', status: 'hidden', priority: 'A', period: 'test' }];
    await context.route('**/api/v1/**', async route => {
      const request = route.request(); const path = new URL(request.url()).pathname;
      let data = [];
      if (path.endsWith('/auth/me')) data = { user };
      else if (path.endsWith('/admin/users/1') && request.method() === 'PATCH') { changed++; data = { ...user, ...request.postDataJSON() }; }
      else if (path.endsWith('/admin/users')) data = [user];
      else if (/\/admin\/(hotspots|analysis)\/1$/.test(path) && request.method() === 'DELETE') { deleted++; articles = []; data = { ok: true }; }
      else if (/\/admin\/(hotspots|analysis)$/.test(path)) data = articles;
      await route.fulfill({ json: { data } });
    });
    const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    for (const section of ['hotspots', 'analysis']) {
      articles = [{ id: 1, slug: 'test', title: 'Test article', status: 'hidden', priority: 'A', category: 'test' }];
      await page.goto(`${base}/admin/${section}`);
      await page.getByText('Test article', { exact: true }).waitFor();
      const before = deleted;
      page.once('dialog', dialog => dialog.dismiss());
      await page.getByRole('button', { name: '删除', exact: true }).click();
      assert.equal(deleted, before, `${section}: cancellation sent DELETE`);
      page.once('dialog', dialog => dialog.accept());
      await page.getByRole('button', { name: '删除', exact: true }).click();
      await page.getByText('已删除', { exact: true }).waitFor();
      assert.equal(deleted, before + 1, `${section}: expected one DELETE`);
      console.log(`PASS ${section} confirmation cancel/accept`);
    }
    await page.goto(`${base}/admin/users`);
    await page.getByRole('button', { name: '禁用', exact: true }).waitFor();
    assert.equal(await page.locator('input[autocomplete="new-password"]').first().getAttribute('type'), 'password');
    page.once('dialog', dialog => dialog.dismiss());
    await page.getByRole('button', { name: '禁用', exact: true }).click();
    assert.equal(changed, 0, 'cancelled account change sent PATCH');
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: '取消管理员', exact: true }).click();
    await page.waitForURL(`${base}/`);
    assert.equal(changed, 1, 'confirmed role change not applied once');
    assert.equal(errors.length, 0, errors.join('\n'));
    console.log('PASS password masking, account confirmation and self-demotion redirect');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

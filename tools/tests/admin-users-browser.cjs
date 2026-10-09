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
    const users = Array.from({ length: 66 }, (_, index) => {
      const id = index + 1;
      return { id, email: `person${id}@example.test`, displayName: `考生${id}`, role: id % 10 === 0 ? 'admin' : 'user', status: id % 3 === 0 ? 'disabled' : 'active', userGroup: id % 2 === 0 ? 'vip' : 'user', mistakeCode: '' };
    });
    let failure = 0;
    await context.route('**/api/v1/**', async route => {
      const request = route.request(), url = new URL(request.url());
      const q = url.searchParams;
      let data = [];
      if (url.pathname.endsWith('/auth/me')) data = { user: admin };
      else if (/\/admin\/users\/\d+$/.test(url.pathname) && request.method() === 'PATCH') {
        const user = users.find(user => user.id === Number(url.pathname.split('/').pop()));
        Object.assign(user, request.postDataJSON()); data = user;
      } else if (url.pathname.endsWith('/admin/users') && request.method() === 'POST') {
        const body = request.postDataJSON();
        data = { id: Math.max(...users.map(user => user.id)) + 1, email: body.email, displayName: body.displayName, role: body.role, status: 'active', userGroup: 'user', mistakeCode: '' };
        users.push(data);
      } else if (url.pathname.endsWith('/admin/users')) {
        if (failure) { await route.fulfill({ status: failure, json: { message: 'test error' } }); return; }
        const keyword = q.get('q') || '';
        const selected = users.filter(user => (!keyword || user.email.includes(keyword) || user.displayName.includes(keyword)) && ['role', 'status', 'userGroup'].every(key => !q.get(key) || user[key] === q.get(key))).sort((a, b) => b.id - a.id);
        const perPage = Number(q.get('perPage') || 20);
        const page = Math.max(1, Math.min(Number(q.get('page') || 1), Math.ceil(selected.length / perPage) || 1));
        data = { items: selected.slice((page - 1) * perPage, page * perPage), total: selected.length, page, perPage };
        if (keyword === 'person1') await new Promise(resolve => setTimeout(resolve, 400));
      }
      await route.fulfill({ json: { data } });
    });
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const heading = page.getByRole('heading', { name: /用户管理（共/ });
    const row = email => page.getByRole('row').filter({ hasText: email });
    const waitTotal = async number => { await page.getByRole('heading', { name: `用户管理（共 ${number} 位用户）`, exact: true }).waitFor(); };
    await page.goto(`${base}/admin/users`); await waitTotal(66);
    assert.equal(await page.locator('tbody tr').count(), 20);
    await page.getByRole('button', { name: '下一页', exact: true }).click();
    await row('person46@example.test').waitFor(); assert.equal(new URL(page.url()).searchParams.get('page'), '2');
    await page.reload(); await row('person46@example.test').waitFor();
    console.log('PASS full total, paging and refresh persistence');
    await page.getByLabel('角色筛选', { exact: true }).selectOption('admin');
    await page.getByLabel('状态筛选', { exact: true }).selectOption('disabled');
    await page.getByLabel('用户组筛选', { exact: true }).selectOption('vip');
    await page.getByRole('button', { name: '筛选', exact: true }).click(); await waitTotal(2);
    assert.equal(await page.locator('tbody tr').count(), 2);
    assert.equal(new URL(page.url()).searchParams.get('page'), null);
    await page.getByRole('button', { name: '重置筛选', exact: true }).click(); await waitTotal(66);
    console.log('PASS combined filters and reset');
    failure = 500;
    await page.getByRole('button', { name: '筛选', exact: true }).click();
    await page.getByRole('alert').waitFor();
    assert.equal(await page.locator('tbody tr').count(), 0);
    assert.equal(await heading.count(), 0);
    assert.equal(await page.getByText('没有匹配的用户。', { exact: true }).count(), 0);
    failure = 0; await page.getByRole('button', { name: '重试', exact: true }).click(); await waitTotal(66);
    console.log('PASS failure distinct from empty and retry');
    const input = page.getByLabel('邮箱或昵称', { exact: true });
    await input.fill('person1'); await page.getByRole('button', { name: '筛选', exact: true }).click();
    await input.fill('person6'); await page.getByRole('button', { name: '筛选', exact: true }).click(); await waitTotal(8);
    await page.waitForTimeout(550);
    assert.equal(await page.locator('tbody tr').count(), 8);
    await row('person66@example.test').waitFor();
    console.log('PASS stale responses do not replace latest search');
    await page.goto(`${base}/admin/users?status=active&perPage=100`); await waitTotal(44);
    page.once('dialog', dialog => dialog.accept());
    await row('person65@example.test').getByRole('button', { name: '禁用', exact: true }).click(); await waitTotal(43);
    assert.equal(await row('person65@example.test').count(), 0);
    console.log('PASS status change leaves filtered list and refreshes total');
    await page.goto(`${base}/admin/users?q=person65&status=disabled`); await waitTotal(1);
    page.once('dialog', dialog => dialog.accept());
    await row('person65@example.test').getByRole('button', { name: '启用', exact: true }).click(); await waitTotal(0);
    await page.getByText('没有匹配的用户。', { exact: true }).waitFor();
    console.log('PASS last filtered record becomes explicit empty result');
    await page.getByLabel('新用户邮箱').fill('created@example.test');
    await page.getByLabel('初始密码').fill('test-password');
    await page.getByRole('button', { name: '创建用户', exact: true }).click();
    await page.getByText('已创建 created@example.test', { exact: true }).waitFor();
    await waitTotal(0);
    assert.equal(new URL(page.url()).searchParams.get('status'), 'disabled');
    await page.getByRole('button', { name: '重置筛选', exact: true }).click(); await waitTotal(67);
    await row('created@example.test').waitFor();
    console.log('PASS creation refresh respects current filters');
    // Put a single matching record on the final page and make it leave the filter.
    for (const user of users) user.status = user.id <= 41 ? 'active' : 'disabled';
    await page.goto(`${base}/admin/users?status=active&page=3`); await waitTotal(41);
    assert.equal(await page.locator('tbody tr').count(), 1);
    page.once('dialog', dialog => dialog.accept());
    await row('person1@example.test').getByRole('button', { name: '禁用', exact: true }).click(); await waitTotal(40);
    await page.waitForURL(url => url.searchParams.get('page') === '2');
    assert.equal(await page.locator('tbody tr').count(), 20);
    console.log('PASS empty final page returns to last valid page');
    failure = 401;
    await page.getByRole('button', { name: '筛选', exact: true }).click();
    await page.getByText('登录已失效，请重新登录。', { exact: true }).waitFor();
    await page.getByRole('link', { name: '重新登录', exact: true }).waitFor();
    failure = 403; await page.getByRole('button', { name: '重试', exact: true }).click();
    await page.getByText('当前账号没有后台权限。', { exact: true }).waitFor();
    assert.equal(errors.length, 0, errors.join('\n'));
    console.log('PASS 401/403 feedback and no browser errors');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

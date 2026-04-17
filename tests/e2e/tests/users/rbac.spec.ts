import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Users RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on user index', async ({ page }) => {
    await page.goto('/user/index');
    await expectForbidden(page);
  });

  test('operator gets 403 on user index', async ({ page }) => {
    await page.goto('/user/index');
    await expectForbidden(page);
  });

  test('viewer gets 403 on user create', async ({ page }) => {
    await page.goto('/user/create');
    await expectForbidden(page);
  });

  test('operator gets 403 on user create', async ({ page }) => {
    await page.goto('/user/create');
    await expectForbidden(page);
  });

  test('viewer gets 403 on user update URL', async ({ page }) => {
    await page.goto('/user/update?id=1');
    await expectForbidden(page);
  });

  test('operator gets 403 on user update URL', async ({ page }) => {
    await page.goto('/user/update?id=1');
    await expectForbidden(page);
  });

  test('viewer gets non-2xx on POST /user/delete', async ({ page }) => {
    const response = await page.request.post('/user/delete?id=999', { data: {} });
    expect([302, 400, 403, 405]).toContain(response.status());
  });

  test('operator gets non-2xx on POST /user/delete', async ({ page }) => {
    const response = await page.request.post('/user/delete?id=999', { data: {} });
    expect([302, 400, 403, 405]).toContain(response.status());
  });
});

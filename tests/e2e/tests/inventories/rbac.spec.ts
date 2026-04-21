import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { BTN_CREATE } from '../../lib/selectors';

test.describe('Inventories RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot see create button', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator(BTN_CREATE)).not.toBeVisible();
  });

  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/inventory/create');
    await expectForbidden(page);
  });

  test('operator can access index', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });
});

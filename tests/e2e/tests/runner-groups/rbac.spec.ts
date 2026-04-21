import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Runner Groups RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view index', async ({ page }) => {
    await page.goto('/runner-group/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/runner-group/create');
    await expectForbidden(page);
  });

  test('operator can create runner groups', async ({ page }) => {
    await page.goto('/runner-group/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });
});

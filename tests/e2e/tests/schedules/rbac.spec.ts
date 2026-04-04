import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Schedules RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on schedule index', async ({ page }) => {
    await page.goto('/schedule/index');
    await expectForbidden(page);
  });

  test('viewer gets 403 on schedule create', async ({ page }) => {
    await page.goto('/schedule/create');
    await expectForbidden(page);
  });

  test('operator can access schedules', async ({ page }) => {
    await page.goto('/schedule/index');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });
});

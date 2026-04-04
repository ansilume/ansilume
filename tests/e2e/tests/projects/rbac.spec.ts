import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { BTN_CREATE } from '../../lib/selectors';

test.describe('Projects RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot see create button', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator(BTN_CREATE)).not.toBeVisible();
  });

  test('viewer gets 403 on project create', async ({ page }) => {
    await page.goto('/project/create');
    await expectForbidden(page);
  });

  test('operator can access project index', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });

  test('operator can create projects', async ({ page }) => {
    await page.goto('/project/create');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });
});

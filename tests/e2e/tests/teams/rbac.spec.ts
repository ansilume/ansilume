import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Teams RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on teams index', async ({ page }) => {
    await page.goto('/team/index');
    await expectForbidden(page);
  });

  test('operator gets 403 on teams index', async ({ page }) => {
    await page.goto('/team/index');
    await expectForbidden(page);
  });

  test('viewer gets 403 on team create', async ({ page }) => {
    await page.goto('/team/create');
    await expectForbidden(page);
  });
});

import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Approvals RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view approvals index', async ({ page }) => {
    await page.goto('/approval/index');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });
});

import { test, expect } from '@playwright/test';

test.describe('Workflow Jobs RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view workflow jobs index', async ({ page }) => {
    await page.goto('/workflow-job/index');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });
});

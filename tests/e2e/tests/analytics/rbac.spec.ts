import { test, expect } from '@playwright/test';

test.describe('Analytics RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view analytics', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator can view analytics', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  // commands/E2eApprovalSeeder.php: a request canceled with its job, of
  // e2e-history-gated-template in e2e-project, which e2e-operator's team operates.
  test('operator sees canceled approval requests counted on a card of their own', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-approvals"]').click();
    const canceled = page.locator('#analytics-approvals-canceled');
    await expect(canceled).toHaveText(/^\d+$/);
    expect(Number(await canceled.textContent()), 'the seeded canceled request').toBeGreaterThanOrEqual(1);
    await expect(page.locator('#analytics-approvals-avg-decision-note')).toHaveText('Canceled requests are not counted.');
  });

  test('viewer sees the canceled card of the approvals tab', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-approvals"]').click();
    await expect(page.locator('#analytics-approvals-canceled')).toHaveText(/^\d+$/);
  });
});

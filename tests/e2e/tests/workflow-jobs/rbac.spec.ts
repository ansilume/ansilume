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
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer cannot see cancel or resume forms', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No workflow job seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Workflow row has no link');
      return;
    }
    await page.goto(href);
    await expect(page.locator('form[action*="/workflow-job/cancel"]')).not.toBeVisible();
    await expect(page.locator('form[action*="/workflow-job/resume"]')).not.toBeVisible();
  });

  test('viewer gets non-2xx on POST /workflow-job/cancel', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No workflow job seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Workflow link has no numeric id');
      return;
    }
    const response = await page.request.post(`/workflow-job/cancel?id=${match[1]}`, { data: {} });
    expect([302, 400, 403]).toContain(response.status());
  });
});

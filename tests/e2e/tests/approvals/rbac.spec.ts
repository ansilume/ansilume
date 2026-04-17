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

  test('viewer cannot see approve or reject buttons', async ({ page }) => {
    await page.goto('/approval/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No approval request seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Approval row has no link');
      return;
    }
    await page.goto(href);
    await expect(page.locator('form[action*="/approval/approve"]')).not.toBeVisible();
    await expect(page.locator('form[action*="/approval/reject"]')).not.toBeVisible();
  });

  test('viewer gets non-2xx on POST /approval/approve', async ({ page }) => {
    await page.goto('/approval/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No approval request seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Approval link has no numeric id');
      return;
    }
    const response = await page.request.post(`/approval/approve?id=${match[1]}`, { data: {} });
    expect([302, 400, 403]).toContain(response.status());
  });
});

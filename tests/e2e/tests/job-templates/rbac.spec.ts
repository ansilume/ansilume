import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Job Templates RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot create templates', async ({ page }) => {
    await page.goto('/job-template/create');
    await expectForbidden(page);
  });

  test('viewer can view template index', async ({ page }) => {
    await page.goto('/job-template/index');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });

  test('operator can create templates', async ({ page }) => {
    await page.goto('/job-template/create');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });

  test('viewer gets 403 on template launch URL', async ({ page }) => {
    await page.goto('/job-template/index');
    const row = page.locator('table.table tbody tr').filter({
      hasText: 'e2e-template',
    }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No e2e-template seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Template link has no numeric id');
      return;
    }
    await page.goto(`/job-template/launch?id=${match[1]}`);
    await expectForbidden(page);
  });
});

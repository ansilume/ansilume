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

  test('viewer sees re-registration notices but no runner actions', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    const row = page.locator('table.table tbody tr', { hasText: 'e2e-reregistered-runner' });
    await expect(row.getByTestId('runner-reregistered-count')).toHaveText('2× in 24 h');
    await expect(row.locator('button:has-text("Regen Token"), button:has-text("Delete")')).toHaveCount(0);
  });

  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/runner-group/create');
    await expectForbidden(page);
  });

  test('operator can create runner groups', async ({ page }) => {
    await page.goto('/runner-group/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  // Fixture from commands/E2eRunnerRegistrationSeeder.php
  test('viewer sees the plain HTTP warning of a runner group', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    await expect(page.getByTestId('runner-group-plaintext-warning')).toContainText('e2e-plaintext-runner');
  });
});

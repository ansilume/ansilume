import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Approval Rules RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await expectForbidden(page);
  });

  test('viewer can view index', async ({ page }) => {
    await page.goto('/approval-rule/index');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });

  test('operator can create approval rules', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });
});

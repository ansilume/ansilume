import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Credentials RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot create credentials', async ({ page }) => {
    await page.goto('/credential/create');
    await expectForbidden(page);
  });

  test('viewer can view index', async ({ page }) => {
    await page.goto('/credential/index');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });

  test('secrets are never visible in DOM', async ({ page }) => {
    await page.goto('/credential/index');
    const bodyHtml = await page.locator('body').innerHTML();
    expect(bodyHtml).not.toContain('e2e-dummy-token-value');
  });

  test('viewer gets 403 on credential create URL', async ({ page }) => {
    await page.goto('/credential/create');
    await expectForbidden(page);
  });

  test('operator can access credential create form', async ({ page }) => {
    await page.goto('/credential/create');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });
});

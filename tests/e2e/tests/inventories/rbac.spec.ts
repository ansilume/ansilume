import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { BTN_CREATE } from '../../lib/selectors';

test.describe('Inventories RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot see create button', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator(BTN_CREATE)).not.toBeVisible();
  });

  test('viewer parsing the vault inventory never sees decrypted values', async ({ page }) => {
    await page.goto('/inventory/index');
    const link = page.locator('table.table tbody tr a', { hasText: /^e2e-vault-inventory$/ }).first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'e2e-vault-inventory is not seeded');
      return;
    }
    await link.click();
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/inventory/parse-hosts')),
      page.locator('#btn-parse-inventory').click(),
    ]);
    await expect(page.locator('#inventory-result').getByTestId('inventory-notice').first()).toBeVisible();
    await expect(page.locator('body')).not.toContainText('E2E-VAULT-PLAINTEXT-MARKER');
  });

  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/inventory/create');
    await expectForbidden(page);
  });

  test('operator can access index', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });
});

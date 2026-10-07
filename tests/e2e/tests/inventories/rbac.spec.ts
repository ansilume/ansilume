import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { BTN_CREATE } from '../../lib/selectors';

/** Opens an inventory's page, paging through the list. */
async function openInventory(page: Page, name: string) {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/inventory/index?page=${listPage}`);
    const link = page.locator('table.table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      return;
    }
  }
  throw new Error(`Inventory ${name} is not listed`);
}

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

  // Fixtures from commands/E2eTeamScopingSeeder.php: e2e-operator's team operates
  // e2e-alpha-proj and may only view e2e-alpha-viewed-proj.
  test('operator cannot move an inventory into a project their team only views', async ({ page }) => {
    // Regression: saving checked only the project the inventory came from.
    await openInventory(page, 'e2e-alpha-inv');
    await page.getByRole('link', { name: 'Edit' }).first().click();
    // The project field shows for file and dynamic inventories.
    await page.locator('#inventory-type').selectOption('dynamic');
    await page.locator('#inventory-project_id').selectOption({ label: 'e2e-alpha-viewed-proj' });
    await page.locator('#inventory-source_path').fill('inventory.yml');
    await page.getByRole('button', { name: 'Save Changes' }).click();
    await expectForbidden(page);

    await openInventory(page, 'e2e-alpha-inv');
    await expect(page.locator('body')).toContainText('e2e-alpha-proj');
    await expect(page.locator('body')).not.toContainText('e2e-alpha-viewed-proj');
  });

  test('operator can access index', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });
});

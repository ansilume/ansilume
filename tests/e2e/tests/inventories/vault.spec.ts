import { test, expect, Page } from '@playwright/test';

// Regression: the server-side "Parse Inventory" honoured the repository's
// ansible.cfg vault settings. It decrypted vaulted group_vars with the
// committed password file (and would run a password script), cached the
// plaintext and showed it to everyone who can open the inventory.
const PLAINTEXT = 'E2E-VAULT-PLAINTEXT-MARKER';

async function openInventory(page: Page, name: string): Promise<boolean> {
  await page.goto('/inventory/index');
  const link = page.locator('table.table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
  if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
    test.skip(true, `${name} is not seeded (ansible-vault missing?)`);
    return false;
  }
  await link.click();
  return true;
}

async function parse(page: Page): Promise<void> {
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/inventory/parse-hosts')),
    page.locator('#btn-parse-inventory').click(),
  ]);
}

test.describe('Inventory vault isolation', () => {
  test('parsing a repository with a committed vault password never shows decrypted values', async ({ page }) => {
    if (!(await openInventory(page, 'e2e-vault-inventory'))) return;
    await parse(page);

    const result = page.locator('#inventory-result');
    await expect(result.getByTestId('inventory-notice').first()).toContainText('were not loaded because they contain vault-encrypted files');
    await expect(result).toContainText('e2e-vault-host');
    await expect(result.getByTestId('vault-encrypted-badge').first()).toBeVisible();
    await expect(page.locator('body')).not.toContainText(PLAINTEXT);

    // The cached result renders the same after a reload.
    await page.reload();
    await expect(page.locator('#inventory-result').getByTestId('inventory-notice').first()).toBeVisible();
    await expect(page.locator('body')).not.toContainText(PLAINTEXT);
  });

  test('a vault-encrypted inventory source shows an explanatory error', async ({ page }) => {
    if (!(await openInventory(page, 'e2e-vault-source-inventory'))) return;
    await parse(page);

    await expect(page.locator('#inventory-result')).toContainText('The inventory source is vault-encrypted');
    await expect(page.locator('body')).not.toContainText(PLAINTEXT);
  });
});

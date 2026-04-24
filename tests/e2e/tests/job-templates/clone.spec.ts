import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

// Clone action: one-click duplicate of a template. The operator lands on
// the edit form for the new row (with "<source> (copy)" pre-filled) and
// adjusts whatever needs adjusting before saving.

test.describe('Job Template Clone', () => {
  test('Clone button on the index row duplicates the template and redirects to edit', async ({ page }) => {
    await page.goto('/job-template/index?sort=-id');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-template' }).first();
    await expect(row).toBeVisible({ timeout: 5_000 });

    await row.getByRole('button', { name: 'Clone' }).click();
    await page.waitForLoadState('domcontentloaded');

    // Must end up on the Update form for the new row.
    await expect(page).toHaveURL(/\/job-template\/update\?id=\d+/);

    // Success flash confirms the clone.
    await expectFlash(page, 'success', 'Cloned');

    // Name field pre-filled with "<source> (copy)".
    const nameInput = page.locator('#jobtemplate-name');
    await expect(nameInput).toHaveValue(/e2e-template \(copy\)/);
  });

  test('Clone button on the detail page produces a new template visible in the index', async ({ page }) => {
    await page.goto('/job-template/index?sort=-id');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-template' }).first();
    await row.locator('a').filter({ hasText: 'e2e-template' }).first().click();
    await page.waitForLoadState('domcontentloaded');

    // Clone from the detail toolbar.
    await page.getByRole('button', { name: 'Clone' }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/\/job-template\/update\?id=\d+/);

    // Save as-is (no rename) — the clone should persist with the
    // auto-picked name, landing us back on the view.
    await page.getByRole('button', { name: /save/i }).first().click();
    await page.waitForLoadState('networkidle');

    await page.goto('/job-template/index?sort=-id');
    // At least one row with "(copy" in the name must now exist.
    await expect(page.locator('table.table tbody tr', { hasText: '(copy' }).first()).toBeVisible();
  });
});

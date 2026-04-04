import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

test.describe('API Tokens', () => {
  test('lists tokens page', async ({ page }) => {
    await page.goto('/profile/tokens');
    await expect(page.locator('h1, h2, .page-title')).toContainText(/token/i);
  });

  test('creates a new token', async ({ page }) => {
    await page.goto('/profile/tokens');
    // The create form is inline on the index — just fill name and submit.
    await page.locator('input[name="name"]').fill('e2e-token');
    await page.locator('#page-content button[type="submit"]:has-text("Generate")').click();
    // Success flash + freshly generated token value is shown once, in a <code> block.
    await expect(page.locator('.alert-success code')).toBeVisible({ timeout: 5_000 });
  });
});

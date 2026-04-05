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

  test('shows dev-mode API explorer links', async ({ page }) => {
    await page.goto('/profile/tokens');
    // E2E env runs with YII_DEBUG=1, so the dev banner is visible.
    const banner = page.locator('#dev-api-explorer');
    await expect(banner).toBeVisible();
    await expect(banner.getByRole('link', { name: /OpenAPI spec/i })).toHaveAttribute('href', '/openapi.yaml');
    await expect(banner.getByRole('link', { name: /Swagger UI/i })).toHaveAttribute('href', /:8088$/);
  });
});

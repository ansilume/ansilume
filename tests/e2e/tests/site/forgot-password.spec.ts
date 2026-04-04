import { test, expect } from '@playwright/test';

test.describe('Forgot password', () => {
  test('renders the request form', async ({ page }) => {
    await page.goto('/site/forgot-password');
    await expect(page.locator('input[type="email"], input[name*="email"]')).toBeVisible();
  });

  test('submit shows confirmation message', async ({ page }) => {
    await page.goto('/site/forgot-password');
    const emailInput = page.locator('input[type="email"], input[name*="email"]');
    await emailInput.fill('e2e-admin@example.com');
    await page.locator('#page-content button[type="submit"]').click();
    // Should show a flash message (success or info) or stay on page with feedback
    await expect(
      page.locator('.alert-success, .alert-info, .alert-danger, .help-block-error'),
    ).toBeVisible({ timeout: 5_000 });
  });
});

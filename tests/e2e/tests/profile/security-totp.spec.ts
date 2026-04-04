import { test, expect } from '@playwright/test';

test.describe('TOTP 2FA', () => {
  test('shows 2FA status page', async ({ page }) => {
    await page.goto('/profile/security');
    await expect(page.locator('body')).toContainText(/two.factor|2fa|authenticator|totp|security/i);
  });
});

import { test, expect } from '@playwright/test';

test.describe('Change Password', () => {
  test('security page shows change password form', async ({ page }) => {
    await page.goto('/profile/security');
    await expect(page.locator('h5', { hasText: 'Change Password' })).toBeVisible();
    await expect(page.locator('#current_password')).toBeVisible();
    await expect(page.locator('#new_password')).toBeVisible();
    await expect(page.locator('#new_password_confirm')).toBeVisible();
    await expect(page.locator('button', { hasText: 'Change Password' })).toBeVisible();
  });

  test('rejects wrong current password', async ({ page }) => {
    await page.goto('/profile/security');
    await page.fill('#current_password', 'wrongpassword');
    await page.fill('#new_password', 'newpassword123');
    await page.fill('#new_password_confirm', 'newpassword123');
    await page.click('button:has-text("Change Password")');

    await expect(page.locator('.alert-danger')).toBeVisible();
  });

  test('rejects mismatched passwords', async ({ page }) => {
    await page.goto('/profile/security');
    await page.fill('#current_password', 'admin123');
    await page.fill('#new_password', 'newpassword123');
    await page.fill('#new_password_confirm', 'different123');
    await page.click('button:has-text("Change Password")');

    await expect(page.locator('.alert-danger')).toBeVisible();
  });
});

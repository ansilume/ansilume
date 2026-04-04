import { test, expect } from '@playwright/test';
import { LOGIN_USERNAME, LOGIN_PASSWORD, LOGIN_SUBMIT, FLASH_DANGER } from '../../lib/selectors';

test.describe('Login page', () => {
  test('renders the login form', async ({ page }) => {
    await page.goto('/site/login');
    await expect(page.locator(LOGIN_USERNAME)).toBeVisible();
    await expect(page.locator(LOGIN_PASSWORD)).toBeVisible();
    await expect(page.locator(LOGIN_SUBMIT)).toBeVisible();
  });

  test('logs in with valid credentials', async ({ page }) => {
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill('e2e-admin');
    await page.locator(LOGIN_PASSWORD).fill('E2eAdminPass1!');
    await page.locator(LOGIN_SUBMIT).click();
    await page.waitForURL('**/site/index', { timeout: 10_000 }).catch(() =>
      page.waitForURL('**/', { timeout: 5_000 }),
    );
    // Should be on the dashboard, not the login page
    await expect(page.locator(LOGIN_USERNAME)).not.toBeVisible();
  });

  test('shows error for wrong password', async ({ page }) => {
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill('e2e-admin');
    await page.locator(LOGIN_PASSWORD).fill('WrongPassword123!');
    await page.locator(LOGIN_SUBMIT).click();
    // Yii2 ActiveForm shows field-level validation errors
    await expect(
      page.locator('.invalid-feedback, .help-block-error, .has-error, .alert-danger, .alert-error, .field-loginform-password.has-error'),
    ).toBeVisible({ timeout: 5_000 });
  });

  test('shows error for nonexistent user', async ({ page }) => {
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill('no-such-user-e2e');
    await page.locator(LOGIN_PASSWORD).fill('SomePass1!');
    await page.locator(LOGIN_SUBMIT).click();
    await expect(
      page.locator('.invalid-feedback, .help-block-error, .has-error, .alert-danger, .alert-error'),
    ).toBeVisible({ timeout: 5_000 });
  });

  test('login page is publicly accessible', async ({ page }) => {
    const response = await page.goto('/site/login');
    expect(response?.status()).toBeLessThan(400);
    await expect(page.locator(LOGIN_USERNAME)).toBeVisible();
  });
});

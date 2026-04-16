import { test, expect } from '@playwright/test';
import { LOGIN_USERNAME, LOGIN_PASSWORD, LOGIN_SUBMIT } from '../../lib/selectors';

const LDAP_USERNAME = 'e2e-ldap-user';

test.describe('LDAP login defenses', () => {
  // The login spec must run without a stored auth state — it tests the
  // public login form. Like site/login.spec.ts, this file has no project
  // dependency and runs under the "unauthenticated" project (matched by
  // the playwright.config.ts testMatch on /login\.spec\.ts/).

  test('LDAP-backed user cannot authenticate through the local password form', async ({ page }) => {
    // With LDAP disabled in the E2E environment (LDAP_ENABLED defaults to
    // false), an account whose auth_source=ldap can never log in: the
    // sentinel password_hash makes password_verify() return false, and the
    // LDAP path short-circuits because the service is not registered.
    // The form must surface the same generic error a wrong local
    // credential gets — no leak of the auth source.
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill(LDAP_USERNAME);
    await page.locator(LOGIN_PASSWORD).fill('whatever-the-attacker-tries');
    await page.locator(LOGIN_SUBMIT).click();

    await expect(
      page.locator('.invalid-feedback, .help-block-error, .has-error, .alert-danger, .alert-error'),
    ).toBeVisible({ timeout: 5_000 });

    // The page must NOT confirm that the username belongs to LDAP — that
    // would be a username enumeration aid.
    await expect(page.locator('body')).not.toContainText(/LDAP|directory/i);

    // We must still be on the login page.
    await expect(page.locator(LOGIN_USERNAME)).toBeVisible();
  });

  test('empty password is rejected for LDAP user as for any other user', async ({ page }) => {
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill(LDAP_USERNAME);
    await page.locator(LOGIN_PASSWORD).fill('');
    await page.locator(LOGIN_SUBMIT).click();
    await expect(
      page.locator('.invalid-feedback, .help-block-error, .has-error, .alert-danger, .alert-error'),
    ).toBeVisible({ timeout: 5_000 });
    await expect(page.locator(LOGIN_USERNAME)).toBeVisible();
  });
});

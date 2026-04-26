import { test, expect } from '@playwright/test';
import { LOGIN_USERNAME, LOGIN_PASSWORD, LOGIN_SUBMIT } from '../../lib/selectors';
import { generateTotp } from '../../lib/totp';

// Hard-coded fixture: same value as commands/E2eTotpUserSeeder::TOTP_SECRET.
// If you change one, change both in the same commit.
const FIXTURE_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

test.describe('TOTP login', () => {
  test('TOTP-enabled user is prompted for a code and succeeds on a valid one', async ({ page }) => {
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill('e2e-totp');
    await page.locator(LOGIN_PASSWORD).fill('E2eTotpPass1!');
    await page.locator(LOGIN_SUBMIT).click();

    // Credential step must hand off to the TOTP verify page, not go straight
    // to the dashboard — regression guard against the TOTP branch being
    // accidentally short-circuited in SiteController::actionLogin().
    await page.waitForURL(/\/verify-totp/, { timeout: 10_000 });
    const codeInput = page.locator('#totpverifyform-code');
    await expect(codeInput).toBeVisible();

    await codeInput.fill(generateTotp(FIXTURE_SECRET));
    await page.locator('#page-content button[type="submit"]').click();

    await page.waitForURL((url) => !/\/(login|verify-totp)(\/|$)/.test(url.pathname), {
      timeout: 10_000,
    });
    // Login landing page: login form must no longer be visible.
    await expect(page.locator(LOGIN_USERNAME)).not.toBeVisible();
  });

  test('wrong TOTP code keeps the user on the verify step', async ({ page }) => {
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill('e2e-totp');
    await page.locator(LOGIN_PASSWORD).fill('E2eTotpPass1!');
    await page.locator(LOGIN_SUBMIT).click();

    await page.waitForURL(/\/verify-totp/, { timeout: 10_000 });
    await page.locator('#totpverifyform-code').fill('000000');
    await Promise.all([
      // Wait for the form-submit navigation to settle before asserting on
      // the re-rendered page; otherwise the validation-error locator can
      // race the navigation and time out under load.
      page.waitForLoadState('domcontentloaded'),
      page.locator('#page-content button[type="submit"]').click(),
    ]);

    // Verify page re-renders with a validation error, user stays unauthenticated.
    await expect(page).toHaveURL(/\/verify-totp$/);
    // Either the field-level invalid-code error, or the rate-limit lockout
    // flash if the test ran enough times to hit the per-user attempt cap.
    // Both prove the bad code wasn't accepted.
    await expect(
      page.locator('.invalid-feedback, .help-block-error, .has-error, .alert-danger'),
    ).toBeVisible({ timeout: 10_000 });
  });
});

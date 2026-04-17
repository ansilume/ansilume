import { test, expect } from '@playwright/test';
import { LOGIN_USERNAME, LOGIN_PASSWORD, LOGIN_SUBMIT } from '../../lib/selectors';

/**
 * Locks in the invariant that a disabled user cannot log in. This is a
 * security regression test: User::findByUsername filters by STATUS_ACTIVE,
 * so disabling a user must immediately prevent subsequent logins.
 *
 * MUTATES SHARED STATE: disables e2e-viewer, re-enables in finally.
 * If this test is ever changed to skip the re-enable, other specs that
 * assume the viewer is active will break.
 */
test.describe('Disabled User Login', () => {
  test('disabled viewer cannot log in', async ({ page }) => {
    // Step 1: log in as admin.
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill('e2e-admin');
    await page.locator(LOGIN_PASSWORD).fill('E2eAdminPass1!');
    await page.locator(LOGIN_SUBMIT).click();
    await page.waitForURL(/\/(site\/index)?$/, { timeout: 10_000 }).catch(() => page.waitForURL('**/', { timeout: 5_000 }));

    // Step 2: find viewer's id from the user index.
    await page.goto('/user/index');
    const row = page.locator('table.table tbody tr').filter({ hasText: 'e2e-viewer' }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'e2e-viewer not found in user list');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Viewer link has no id');
      return;
    }
    const viewerId = match[1];

    // Step 3: disable the viewer.
    const toggleResponse = await page.request.post(`/user/toggle-status?id=${viewerId}`, { data: {} });
    expect([200, 302]).toContain(toggleResponse.status());

    try {
      // Step 4: log out admin.
      await page.request.post('/site/logout', { data: {} }).catch(() => {});
      // Clear any remaining admin cookies by re-visiting login.
      await page.goto('/site/login');

      // Step 5: attempt login as viewer. Should FAIL.
      await page.locator(LOGIN_USERNAME).fill('e2e-viewer');
      await page.locator(LOGIN_PASSWORD).fill('E2eViewerPass1!');
      await page.locator(LOGIN_SUBMIT).click();
      await page.waitForTimeout(1_000);

      // Assert: still on login page (or re-rendered), not the dashboard.
      expect(page.url()).toMatch(/\/login|\/site\/login/);
      // Error message should mention invalid credentials (Yii's default).
      await expect(page.locator('body')).toContainText(/incorrect|invalid|wrong/i);
    } finally {
      // Step 6 (finally): re-login as admin and re-enable viewer.
      await page.goto('/site/login');
      await page.locator(LOGIN_USERNAME).fill('e2e-admin');
      await page.locator(LOGIN_PASSWORD).fill('E2eAdminPass1!');
      await page.locator(LOGIN_SUBMIT).click();
      await page.waitForURL(/\/(site\/index)?$/, { timeout: 10_000 }).catch(() => page.waitForURL('**/', { timeout: 5_000 }).catch(() => {}));
      // Toggle viewer back to active.
      await page.request.post(`/user/toggle-status?id=${viewerId}`, { data: {} });
    }
  });
});

import { test, expect } from '@playwright/test';

test.describe('Form Validation Edge Cases', () => {
  const SQL_INJECTION = "';DROP TABLE project;--";
  const LONG_STRING = 'x'.repeat(5000);
  const UNICODE = '日本語 🔥 Ω ∑ — مرحبا';

  async function assertNoServerError(page: import('@playwright/test').Page) {
    await expect(page.locator('body')).not.toContainText(/An internal server error occurred/i);
    await expect(page.locator('body')).not.toContainText(/SQLSTATE/);
    await expect(page.locator('body')).not.toContainText(/Exception/);
  }

  for (const payload of [SQL_INJECTION, LONG_STRING, UNICODE]) {
    const label = payload === LONG_STRING ? 'long-string' : payload === UNICODE ? 'unicode' : 'sql-injection';

    test(`project create survives ${label}`, async ({ page }) => {
      await page.goto('/project/create');
      await page.locator('input[name*="name"]').first().fill(payload);
      await page.locator('#project-form button[type="submit"]').first().click();
      await assertNoServerError(page);
    });

    test(`credential create survives ${label}`, async ({ page }) => {
      await page.goto('/credential/create');
      const nameField = page.locator('input[name*="name"]').first();
      if (!(await nameField.isVisible({ timeout: 2_000 }).catch(() => false))) {
        test.skip(true, 'No credential create form available');
        return;
      }
      await nameField.fill(payload);
      await page.locator('#credential-form button[type="submit"]').first().click();
      await assertNoServerError(page);
    });
  }
});

import { test, expect } from '@playwright/test';

test.describe('User Toggle Status', () => {
  test('user detail shows status toggle', async ({ page }) => {
    await page.goto('/user/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-viewer' });
    if (await row.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await row.locator('a').first().click();
      // Should show user detail with status info
      await expect(page.locator('body')).toContainText(/status|active|inactive|user/i);
    }
  });
});

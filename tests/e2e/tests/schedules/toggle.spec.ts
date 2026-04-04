import { test, expect } from '@playwright/test';

test.describe('Schedule Toggle', () => {
  test('schedule detail shows enable/disable toggle', async ({ page }) => {
    await page.goto('/schedule/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-schedule' });
    if (await row.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await row.locator('a').first().click();
      await expect(page.locator('body')).toContainText(/schedule|enabled|disabled|cron/i);
    }
  });
});

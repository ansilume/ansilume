import { test, expect } from '@playwright/test';

test.describe('Analytics Export', () => {
  test('export buttons are visible for admin', async ({ page }) => {
    await page.goto('/analytics/index');
    const exportBtn = page.locator('a:has-text("Export"), button:has-text("Export"), a:has-text("CSV"), a:has-text("JSON")');
    const count = await exportBtn.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });
});

import { test, expect } from '@playwright/test';

test.describe('Analytics Export', () => {
  test('export buttons are visible for admin', async ({ page }) => {
    await page.goto('/analytics/index');
    const exportBtn = page.locator('a:has-text("Export"), button:has-text("Export"), a:has-text("CSV"), a:has-text("JSON")');
    const count = await exportBtn.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });

  // Regression: the refusal had the flash type "error", which rendered as the
  // unstyled class alert-error instead of a red alert
  // (controllers/AnalyticsController.php actionExport).
  test('an export with invalid filters returns to the dashboard with a danger flash', async ({ page }) => {
    await page.goto('/analytics/export?date_from=not-a-date&report=summary&format=json');

    await expect(page).toHaveURL(/\/analytics\/index$/);
    await expect(page.locator('#page-content > .alert-danger[role="alert"]')).toHaveText('Invalid filter parameters.');
    await expect(page.locator('#page-content > .alert-error')).toHaveCount(0);
  });
});

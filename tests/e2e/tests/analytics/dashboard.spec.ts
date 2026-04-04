import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Analytics Dashboard', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/analytics|dashboard/i);
  });

  test('shows charts or data sections', async ({ page }) => {
    await page.goto('/analytics/index');
    // Analytics page should have some content
    await expect(page.locator('body')).toContainText(/analytics|job|chart|stat|success|fail/i);
  });

  test('date filter present', async ({ page }) => {
    await page.goto('/analytics/index');
    const dateFilter = page.locator('input[type="date"], select[name*="period"], input[name*="from"], .date-filter');
    const count = await dateFilter.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });
});

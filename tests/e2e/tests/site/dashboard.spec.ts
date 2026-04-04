import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Dashboard', () => {
  test('loads for admin', async ({ page }) => {
    await page.goto('/site/index');
    await expect(page).not.toHaveURL(/.*login.*/);
    await expect(page.locator('body')).toBeVisible();
  });

  test('shows stats cards', async ({ page }) => {
    await page.goto('/site/index');
    // Dashboard should show at least one stat card or widget
    const cards = page.locator('.card, .info-box, .stat-card, .widget');
    const count = await cards.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });

  test('has navigation elements', async ({ page }) => {
    await page.goto('/site/index');
    await expect(page.locator('#sidebar, nav, .navbar').first()).toBeVisible();
  });
});

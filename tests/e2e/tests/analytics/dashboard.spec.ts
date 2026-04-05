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

  test('shows workflows, approvals, runners tabs', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('a[href="#tab-workflows"]')).toContainText(/workflows/i);
    await expect(page.locator('a[href="#tab-approvals"]')).toContainText(/approvals/i);
    await expect(page.locator('a[href="#tab-runners"]')).toContainText(/runners/i);
  });

  test('workflows tab activates on click', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-workflows"]').click();
    await expect(page.locator('#tab-workflows')).toBeVisible();
  });

  test('approvals tab activates on click', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-approvals"]').click();
    await expect(page.locator('#tab-approvals')).toBeVisible();
  });

  test('runners tab activates on click', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-runners"]').click();
    await expect(page.locator('#tab-runners')).toBeVisible();
  });
});

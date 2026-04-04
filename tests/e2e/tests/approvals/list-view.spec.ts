import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Approvals List', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/approval/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/approval/i);
  });

  test('shows pending approvals or empty state', async ({ page }) => {
    await page.goto('/approval/index');
    const rows = page.locator('table.table tbody tr');
    const empty = page.locator('.empty, :text("No results"), :text("no approval")');
    const hasRows = await rows.count();
    const isEmpty = await empty.isVisible({ timeout: 2_000 }).catch(() => false);
    expect(hasRows > 0 || isEmpty).toBeTruthy();
  });
});

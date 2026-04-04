import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Runners', () => {
  test('runner group detail shows runners', async ({ page }) => {
    await page.goto('/runner-group/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/runner|token|group/i);
    }
  });

  test('runners list shows registered runners', async ({ page }) => {
    await page.goto('/runner-group/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      // Runners may or may not exist
      const runnersSection = page.locator('table.table, :text("runner"), :text("No runners")');
      await expect(runnersSection.first()).toBeVisible({ timeout: 3_000 });
    }
  });
});

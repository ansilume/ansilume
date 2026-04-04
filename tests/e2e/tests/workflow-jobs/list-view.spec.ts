import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Workflow Jobs List', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/workflow-job/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/workflow/i);
  });

  test('shows jobs or empty state', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const rows = page.locator('table.table tbody tr');
    const empty = page.locator('.empty, :text("No results"), :text("no workflow")');
    const hasRows = await rows.count();
    const isEmpty = await empty.isVisible({ timeout: 2_000 }).catch(() => false);
    expect(hasRows > 0 || isEmpty).toBeTruthy();
  });
});

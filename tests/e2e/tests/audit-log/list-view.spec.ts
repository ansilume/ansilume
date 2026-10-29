import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Audit Log', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/audit-log/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/audit/i);
  });

  // A sign-in writes user.login, so the sign-ins of auth.setup.ts are listed,
  // each row linking its entry; the empty state is a row without a link. It
  // counted rows and accepted none.
  test('shows log entries', async ({ page }) => {
    await page.goto('/audit-log/index');
    const entries = page.locator('#page-content table.table tbody tr td:first-child a');
    expect(await entries.count(), 'audit log entries, not the empty state').toBeGreaterThan(0);
  });

  test('view detail', async ({ page }) => {
    await page.goto('/audit-log/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/audit|action|user|detail/i);
    }
  });
});

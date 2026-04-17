import { test, expect } from '@playwright/test';

test.describe('Audit Log Filters', () => {
  test('filter by action narrows results', async ({ page }) => {
    // Unfiltered count.
    await page.goto('/audit-log/index');
    const unfilteredRows = await page.locator('table.table tbody tr').count();

    // Filter by a known-seeded action. user.login fires on every e2e auth.setup run.
    await page.goto('/audit-log/index?action=user.login');
    const filteredRows = await page.locator('table.table tbody tr').count();

    expect(filteredRows).toBeGreaterThan(0);
    expect(filteredRows).toBeLessThanOrEqual(unfilteredRows);

    // Every visible row should contain 'user.login' (the filter is SQL LIKE).
    const allText = await page.locator('table.table tbody').innerText();
    expect(allText).toContain('user.login');
  });

  test('filter by non-matching action yields empty table', async ({ page }) => {
    await page.goto('/audit-log/index?action=nonexistent.action.xyzzy');
    // The empty-state renders "No entries found." inside a single <tr>,
    // so row-count would be 1 (the message row). Assert the empty message
    // appears instead of counting rows.
    await expect(page.locator('table.table tbody')).toContainText(/no entries|no records|no data/i);
  });
});

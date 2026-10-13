import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { HISTORY_RULE, REQUEST_JOB_COLUMN, REQUEST_RULE_COLUMN, REQUEST_STATUS_COLUMN } from '../../lib/approvals';

test.describe('Approvals List', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/approval/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/approval/i);
  });

  test('lists each request with its job, rule and status', async ({ page }) => {
    // commands/E2eApprovalSeeder.php: on e2e-history-approval-rule, a request
    // whose job was canceled, and one that expired (pending until the
    // maintenance run times it out).
    const rows = rowsWhere(await indexRows(page, '/approval/index'), REQUEST_RULE_COLUMN, HISTORY_RULE);
    const statuses = rows.map((row) => row.cells[REQUEST_STATUS_COLUMN]).sort();
    expect(statuses).toHaveLength(2);
    expect(statuses[0]).toBe('Canceled');
    expect(['Pending', 'Timed Out']).toContain(statuses[1]);
    for (const row of rows) {
      expect(row.cells[REQUEST_JOB_COLUMN]).toMatch(/^#\d+$/);
    }
  });
});

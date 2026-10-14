import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';
import { indexRows, rowsWhere } from '../../lib/scoping';
import {
  HISTORY_RULE,
  HISTORY_TEMPLATE,
  REQUEST_EXPIRES_COLUMN,
  REQUEST_FOR_COLUMN,
  REQUEST_JOB_COLUMN,
  REQUEST_LIST_COLUMNS,
  REQUEST_RULE_COLUMN,
  REQUEST_STATUS_COLUMN,
} from '../../lib/approvals';
import { forJobTemplate } from '../../lib/runs';

test.describe('Approvals List', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/approval/index');
    await expect(page.locator(PAGE_TITLE).first()).toHaveText('Approval Requests');
    await expect(page.locator('#approval-requests-table thead th')).toHaveText(REQUEST_LIST_COLUMNS);
  });

  test('lists each request with its job, what it is for, its rule and status', async ({ page }) => {
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
      // A job of a job template: the request is for the template.
      expect(row.cells[REQUEST_FOR_COLUMN]).toBe(forJobTemplate(HISTORY_TEMPLATE));
      // Only a pending request expires; a resolved one shows a dash.
      expect(row.cells[REQUEST_EXPIRES_COLUMN]).toMatch(row.cells[REQUEST_STATUS_COLUMN] === 'Pending' ? /ago$/ : /^—$/);
    }
  });
});

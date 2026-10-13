import { test, expect } from '@playwright/test';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { PAGE_TITLE } from '../../lib/selectors';
import { LOCKED_WORKFLOW } from '../../lib/workflows';

test.describe('Workflow Jobs List', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/workflow-job/index');
    await expect(page.locator(PAGE_TITLE).first()).toContainText(/workflow/i);
    await expect(page.locator('#page-content table.table thead th')).toHaveText(['#', 'Workflow', 'Status', 'Launched By', 'Started', 'Finished']);
  });

  test('lists the seeded run with its workflow, status and launcher', async ({ page }) => {
    // commands/E2eWorkflowGateSeeder.php: e2e-admin's run of e2e-locked-workflow waits at its pause step.
    const rows = rowsWhere(await indexRows(page, '/workflow-job/index'), 1, LOCKED_WORKFLOW);
    expect(rows).toHaveLength(1);
    expect(rows[0].cells[2]).toBe('Running');
    expect(rows[0].cells[3]).toBe('e2e-admin');
  });
});

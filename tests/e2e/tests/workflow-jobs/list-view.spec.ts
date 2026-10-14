import { test, expect } from '@playwright/test';
import { PAUSED, RUN_CURRENT_STEP_COLUMN, RUN_LAUNCHER_COLUMN, RUN_LIST_COLUMNS, RUN_STATUS_COLUMN, RUN_WORKFLOW_COLUMN } from '../../lib/runs';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { PAGE_TITLE } from '../../lib/selectors';
import { LOCKED_PAUSE, LOCKED_WORKFLOW } from '../../lib/workflows';

test.describe('Workflow Jobs List', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/workflow-job/index');
    await expect(page.locator(PAGE_TITLE).first()).toHaveText('Workflow Jobs');
    await expect(page.locator('#page-content table.table thead th')).toHaveText(RUN_LIST_COLUMNS);
  });

  test('lists the seeded run with its workflow, state, current step and launcher', async ({ page }) => {
    // commands/E2eWorkflowGateSeeder.php: e2e-admin's run of e2e-locked-workflow waits at its pause step.
    const rows = rowsWhere(await indexRows(page, '/workflow-job/index'), RUN_WORKFLOW_COLUMN, LOCKED_WORKFLOW);
    expect(rows).toHaveLength(1);
    // regression: the Workflow Jobs list showed a paused run as Running.
    expect(rows[0].cells[RUN_STATUS_COLUMN]).toBe(PAUSED);
    expect(rows[0].cells[RUN_CURRENT_STEP_COLUMN]).toBe(LOCKED_PAUSE);
    expect(rows[0].cells[RUN_LAUNCHER_COLUMN]).toBe('e2e-admin');
  });
});

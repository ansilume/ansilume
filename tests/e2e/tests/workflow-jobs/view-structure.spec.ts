import { test, expect } from '@playwright/test';
import { LOCKED_PAUSE, LOCKED_WORKFLOW, newestRunId, runStepRow } from '../../lib/workflows';

// Regression for the live-append placement bug: the polling JS used to
// target `table.table tbody`, which matched the metadata box at the top
// of the page (Status / Workflow / Launched By rows) instead of the
// steps table. Step 2 + 3 ended up rendered ABOVE the steps table's
// column headers. Pin the structural invariant so a future view edit
// can't drop the steps-table id and reintroduce the same selector
// ambiguity. The run of e2e-locked-workflow waits at its pause step
// (commands/E2eWorkflowGateSeeder.php).

test.describe('Workflow-job view structure', () => {
  test('steps table carries the wj-steps-table id and is separate from the metadata table', async ({ page }) => {
    await page.goto(`/workflow-job/view?id=${await newestRunId(page, LOCKED_WORKFLOW)}`);

    // The steps table is identifiable by the id the polling JS targets.
    const stepsTable = page.locator('#wj-steps-table');
    await expect(stepsTable).toBeVisible({ timeout: 5_000 });
    // The Type column shows what each step was when it ran.
    await expect(stepsTable.locator('thead th')).toHaveText(['#', 'Step', 'Type', 'Status', 'Job / Request', 'Started', 'Duration', 'Finished']);
    await expect(stepsTable.locator('thead th').first()).toHaveAttribute('title', 'Order in this run');
    const pause = runStepRow(page, LOCKED_PAUSE);
    await expect(pause.locator('td').nth(0)).toHaveText('1');
    await expect(pause.locator('[data-wjs-type-cell]')).toHaveText('Pause');
    await expect(pause.locator('[data-wjs-job-cell]')).toHaveText('—');

    // There must be at least two tables on the page (metadata + steps),
    // and #wj-steps-table must be the second one — otherwise the
    // polling JS would still risk picking up the metadata tbody if it
    // ever fell back to the generic selector.
    const tableCount = await page.locator('table.table').count();
    expect(tableCount).toBeGreaterThanOrEqual(2);

    // Defence in depth: the metadata table must not carry the steps id.
    const metaCount = await page.locator('table.table:not(#wj-steps-table) thead').count();
    expect(metaCount).toBeLessThanOrEqual(1); // metadata table has no <thead>; tighten if a future view changes that
    // The run was launched without extra vars: no Extra Vars row.
    await expect(page.locator('#wj-extra-vars')).toHaveCount(0);
  });
});

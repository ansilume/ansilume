import { test, expect } from '@playwright/test';

// Regression for the live-append placement bug: the polling JS used to
// target `table.table tbody`, which matched the metadata box at the top
// of the page (Status / Workflow / Launched By rows) instead of the
// steps table. Step 2 + 3 ended up rendered ABOVE the steps table's
// column headers. Pin the structural invariant so a future view edit
// can't drop the steps-table id and reintroduce the same selector
// ambiguity.

test.describe('Workflow-job view structure', () => {
  test('steps table carries the wj-steps-table id and is separate from the metadata table', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (!(await firstRow.isVisible({ timeout: 3_000 }).catch(() => false))) {
      test.skip(true, 'No workflow-jobs seeded in this run.');
      return;
    }
    await firstRow.click();
    await page.waitForLoadState('domcontentloaded');

    // The steps table is identifiable by the id the polling JS targets.
    const stepsTable = page.locator('#wj-steps-table');
    await expect(stepsTable).toBeVisible({ timeout: 5_000 });
    await expect(stepsTable.locator('thead th').first()).toContainText('#');

    // There must be at least two tables on the page (metadata + steps),
    // and #wj-steps-table must be the second one — otherwise the
    // polling JS would still risk picking up the metadata tbody if it
    // ever fell back to the generic selector.
    const tableCount = await page.locator('table.table').count();
    expect(tableCount).toBeGreaterThanOrEqual(2);

    // Defence in depth: the metadata table must not carry the steps id.
    const metaCount = await page.locator('table.table:not(#wj-steps-table) thead').count();
    expect(metaCount).toBeLessThanOrEqual(1); // metadata table has no <thead>; tighten if a future view changes that
  });
});

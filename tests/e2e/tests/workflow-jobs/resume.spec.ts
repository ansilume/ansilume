import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { runStepRow } from '../../lib/workflows';

// commands/E2eWorkflowPausedSeeder.php: e2e-paused-workflow, a pause step,
// then a job step, with a run paused at the pause step.
const PAUSED_WORKFLOW = 'e2e-paused-workflow';

test.describe('Workflow Job Resume', () => {
  test('admin resumes a paused workflow job', async ({ page }) => {
    // Columns of the run index: #, Workflow, Status, ...; any page of it.
    const running = rowsWhere(await indexRows(page, '/workflow-job/index'), 1, PAUSED_WORKFLOW)
      .filter((row) => row.cells[2] === 'Running');
    expect(running.length, `a running run of ${PAUSED_WORKFLOW}`).toBeGreaterThan(0);
    await page.goto(`/workflow-job/view?id=${running[0].id}`);

    const pause = runStepRow(page, 'Wait for manual resume');
    await expect(pause.locator('[data-wjs-type-cell]')).toHaveText('Pause');
    await expect(pause.locator('[data-wjs-status-cell] .badge')).toHaveText('Running');

    // The Resume button is rendered as:
    //   <form action="/workflow-job/resume?id=N" method="post">
    //     <button type="submit" class="btn btn-success btn-sm">Resume</button>
    //   </form>
    // It is only shown when hasPausedStep && user has workflow.launch permission.
    const resume = page.locator('form[action*="/workflow-job/resume"] button[type="submit"]');
    await expect(resume).toBeVisible({ timeout: 5_000 });
    await expect(resume).toBeEnabled();

    // The form has an onsubmit confirm() dialog — accept it.
    page.once('dialog', (dialog) => dialog.accept());
    await resume.click();

    await expectFlash(page, 'success', 'Paused step resumed.');
    await expect(pause.locator('[data-wjs-status-cell] .badge')).toHaveText('Succeeded');
    // The run went on with the job step.
    await expect(runStepRow(page, 'Post-resume job').locator('[data-wjs-type-cell]')).toHaveText('Job');
    await expect(page.locator('form[action*="/workflow-job/resume"]')).toHaveCount(0);
  });
});

import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { PAUSED, PAUSED_STEP, PAUSED_WORKFLOW, RUN_STATUS_COLUMN, RUN_WORKFLOW_COLUMN, cancelRunIfActive, stepBadge } from '../../lib/runs';
import { runStepRow } from '../../lib/workflows';

// commands/E2eWorkflowPausedSeeder.php: e2e-paused-workflow, a pause step,
// then a job step, with a run paused at the pause step.

test.describe('Workflow Job Resume', () => {
  let resumed = '';

  // The resumed run goes on with a job that may wait for a runner: none is left waiting.
  test.afterEach(async ({ page }) => {
    await cancelRunIfActive(page, resumed);
    resumed = '';
  });

  test('admin resumes a paused workflow job', async ({ page }) => {
    // Any page of the run index.
    const paused = rowsWhere(await indexRows(page, '/workflow-job/index'), RUN_WORKFLOW_COLUMN, PAUSED_WORKFLOW)
      .filter((row) => row.cells[RUN_STATUS_COLUMN] === PAUSED);
    expect(paused.length, `a paused run of ${PAUSED_WORKFLOW}`).toBeGreaterThan(0);
    const runId = paused[0].id;
    await page.goto(`/workflow-job/view?id=${runId}`);

    const pause = runStepRow(page, PAUSED_STEP);
    await expect(pause.locator('[data-wjs-type-cell]')).toHaveText('Pause');
    await expect(stepBadge(pause)).toHaveText(PAUSED);

    // The Resume button is rendered as:
    //   <form id="wj-resume-form" action="/workflow-job/resume?id=N" method="post">
    //     <button type="submit" class="btn btn-success btn-sm">Resume</button>
    //   </form>
    // for a user with workflow.launch and operator access while the run is
    // unfinished; it is shown only while the run is paused.
    const resume = page.locator('form[action*="/workflow-job/resume"] button[type="submit"]');
    await expect(resume).toBeVisible({ timeout: 5_000 });
    await expect(resume).toBeEnabled();

    // The form has an onsubmit confirm() dialog — accept it.
    page.once('dialog', (dialog) => dialog.accept());
    await resume.click();
    resumed = runId;

    await expectFlash(page, 'success', 'Paused step resumed.');
    await expect(stepBadge(pause)).toHaveText('Succeeded');
    // The run went on with the job step: nothing to resume any more.
    await expect(runStepRow(page, 'Post-resume job').locator('[data-wjs-type-cell]')).toHaveText('Job');
    await expect(page.locator('form[action*="/workflow-job/resume"]')).toBeHidden();
  });
});

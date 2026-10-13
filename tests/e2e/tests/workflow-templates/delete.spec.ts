import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import { DELETE_PAUSE, DELETE_WORKFLOW, activeRunId, runStepRow } from '../../lib/workflows';

/**
 * Deleting a workflow template cancels its running runs: the confirmation
 * says so beforehand and the flash says how many were canceled afterwards.
 * Fixture: e2e-delete-workflow, a pause step and a job step, with a run
 * paused at the pause step (commands/E2eWorkflowGateSeeder.php); every seed
 * restores it. The operator, who holds no workflow-template.delete:
 * workflow-templates/rbac.spec.ts.
 */
test.describe('Delete a workflow template', () => {
  test('deleting a workflow template cancels its running run', async ({ page }) => {
    const id = await openWorkflowTemplate(page, DELETE_WORKFLOW);
    const runId = await activeRunId(page);

    const questions: string[] = [];
    page.once('dialog', async (dialog) => {
      questions.push(dialog.message());
      await dialog.accept();
    });
    await Promise.all([
      page.waitForURL(/\/workflow-template\/index/),
      page.locator('#wf-delete-form button[type="submit"]').click(),
    ]);
    expect(questions).toEqual(['Delete this workflow template? Its 1 running run(s) will be canceled.']);
    await expectFlash(page, 'success', `Workflow template "${DELETE_WORKFLOW}" deleted. 1 running run(s) were canceled.`);

    expect(rowsWhere(await indexRows(page, '/workflow-template/index'), 0, DELETE_WORKFLOW)).toHaveLength(0);
    const gone = await page.goto(`/workflow-template/view?id=${id}`);
    expect(gone?.status()).toBe(404);

    // The run ended as canceled, and its paused step says why.
    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(page.locator('#wj-status')).toHaveText('Canceled');
    await expect(page.locator('#wj-live')).toHaveCount(0);
    await expect(page.locator('form[action*="/workflow-job/resume"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/workflow-job/cancel"]')).toHaveCount(0);
    const pause = runStepRow(page, DELETE_PAUSE);
    await expect(pause.locator('[data-wjs-type-cell]')).toHaveText('Pause');
    await expect(pause.locator('[data-wjs-status-cell] .badge')).toHaveText('Failed');
    await expect(pause.locator('[data-wjs-error]')).toHaveText('Not finished: the run was canceled because its workflow template was deleted.');
    await expect(page.locator('#wj-steps-table tbody tr')).toHaveCount(1);
  });
});

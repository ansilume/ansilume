import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import { LOCKED_PAUSE, LOCKED_WORKFLOW, activeRunId, runStepRow, templateStepRow } from '../../lib/workflows';

/**
 * A run follows the live steps of its workflow at every transition, so the
 * steps cannot change while a run of the workflow is active: the template
 * page says which runs block it and offers no step changes, and forged step
 * changes are refused with a danger flash. Validation errors still come
 * first.
 *
 * Fixture: e2e-locked-workflow, a pause step and a job step, with a run
 * paused at the pause step that no spec resumes or cancels
 * (commands/E2eWorkflowGateSeeder.php).
 */
const LOCKED_JOB = 'e2e-locked-job';

function runsActive(runId: string): string {
  return `Steps cannot change while run(s) #${runId} of this workflow are active: wait for them to finish or cancel them.`;
}

test.describe('Steps of a workflow with an active run', () => {
  test('the page names the active run and offers no step changes', async ({ page }) => {
    await openWorkflowTemplate(page, LOCKED_WORKFLOW);
    const runId = await activeRunId(page);
    await expect(page.locator('#wf-active-runs-notice')).toHaveText(runsActive(runId));
    await expect(page.locator('#add-step-form')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/move-step"]')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/remove-step"]')).toHaveCount(0);
    await expect(page.locator('#workflow-steps-table thead th')).toHaveText(['#', 'Name', 'Type', 'Target', 'On Success', 'On Failure', 'Always']);
    // Renaming, launching and the trigger token are no step changes.
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
    await expect(page.locator('#page-content form[action*="/workflow-template/launch"] button')).toBeVisible();

    // The notice links the run, which waits at its pause step.
    await page.locator('#wf-active-runs-notice a').click();
    await expect(page).toHaveURL(new RegExp(`/workflow-job/view\\?id=${runId}$`));
    await expect(page.locator('#wj-status')).toHaveText('Running');
    const pause = runStepRow(page, LOCKED_PAUSE);
    await expect(pause.locator('[data-wjs-type-cell]')).toHaveText('Pause');
    await expect(pause.locator('[data-wjs-status-cell] .badge')).toHaveText('Running');
    await expect(pause).toHaveClass(/table-active/);
  });

  test('forged step changes are refused while the run is active, an invalid step first', async ({ page }) => {
    const id = await openWorkflowTemplate(page, LOCKED_WORKFLOW);
    const runId = await activeRunId(page);
    const pauseStepId = (await templateStepRow(page, LOCKED_PAUSE).getAttribute('data-step-id')) ?? '';
    const jobStepId = (await templateStepRow(page, LOCKED_JOB).getAttribute('data-step-id')) ?? '';
    expect(pauseStepId).toMatch(/^\d+$/);
    expect(jobStepId).toMatch(/^\d+$/);

    // An invalid step is refused for what is wrong with it, before the run is looked at.
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const invalid = await page.request.post(`/workflow-template/add-step?id=${id}`, {
      headers: { 'X-CSRF-Token': csrf ?? '' },
      data: { WorkflowStep: { name: 'e2e-locked-invalid', step_type: true, step_order: 30 } },
    });
    expect(invalid.status()).toBe(200);
    expect(await invalid.text()).toContain('Step not added: Step Type must be a string.');

    // Real form posts with a valid CSRF token: only the server checks may refuse them.
    const changes: [string, Record<string, string>, string][] = [
      [
        `/workflow-template/add-step?id=${id}`,
        { 'WorkflowStep[name]': 'e2e-locked-new', 'WorkflowStep[step_type]': 'pause', 'WorkflowStep[step_order]': '30' },
        `Step not added: ${runsActive(runId)}`,
      ],
      [`/workflow-template/remove-step?id=${id}`, { step_id: jobStepId }, runsActive(runId)],
      [`/workflow-template/move-step?id=${id}`, { step_id: pauseStepId, direction: 'down' }, runsActive(runId)],
    ];
    for (const [action, fields, refusal] of changes) {
      await page.goto('/workflow-template/index');
      await forgePost(page, action, fields, /\/workflow-template\/view/);
      await expectFlash(page, 'danger', refusal);
    }

    await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(2);
    await expect(page.locator('#workflow-steps-table tbody tr').nth(0)).toContainText(LOCKED_PAUSE);
    await expect(page.locator('#workflow-steps-table tbody tr').nth(1)).toContainText(LOCKED_JOB);
    await expect(page.locator('#workflow-steps-table')).not.toContainText('e2e-locked-new');
    await expect(page.locator('#workflow-steps-table')).not.toContainText('e2e-locked-invalid');
    expect(await activeRunId(page)).toBe(runId);
  });
});

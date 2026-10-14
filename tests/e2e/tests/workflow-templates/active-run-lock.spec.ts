import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  LOCKED_PAUSE,
  LOCKED_WORKFLOW,
  STEPS_COLUMNS,
  STEP_NOT_ADDED,
  STEP_NOT_MOVED,
  STEP_NOT_SAVED,
  activeRunId,
  runStepRow,
  runsActive,
  templateStepId,
  templateStepNames,
} from '../../lib/workflows';

/**
 * A run follows the live steps of its workflow at every transition, so the
 * steps cannot change while a run of the workflow is active: the template
 * page says which runs block it and offers no step changes, the edit page
 * of a step shows them too with a form that cannot be sent, and forged step
 * changes are refused. Validation errors still come first.
 *
 * Fixture: e2e-locked-workflow, a pause step and a job step, with a run
 * paused at the pause step that no spec resumes or cancels
 * (commands/E2eWorkflowGateSeeder.php).
 */
const LOCKED_JOB = 'e2e-locked-job';

test.describe('Steps of a workflow with an active run', () => {
  test('the page names the active run and offers no step changes', async ({ page }) => {
    await openWorkflowTemplate(page, LOCKED_WORKFLOW);
    const runId = await activeRunId(page);
    await expect(page.locator('#wf-active-runs-notice')).toHaveText(runsActive(`#${runId}`));
    await expect(page.locator('#add-step-form')).toHaveCount(0);
    await expect(page.locator('#page-content [data-action]')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/move-step"]')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/remove-step"]')).toHaveCount(0);
    await expect(page.locator('#workflow-steps-table thead th')).toHaveText(STEPS_COLUMNS);
    // Renaming, launching and the trigger token are no step changes.
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
    await expect(page.locator('#page-content form[action*="/workflow-template/launch"] button')).toBeVisible();

    // The notice links the run, which waits at its pause step.
    await page.locator('#wf-active-runs-notice a').click();
    await expect(page).toHaveURL(new RegExp(`/workflow-job/view\\?id=${runId}$`));
    await expect(page.locator('#wj-status')).toHaveText('Paused');
    const pause = runStepRow(page, LOCKED_PAUSE);
    await expect(pause.locator('[data-wjs-type-cell]')).toHaveText('Pause');
    await expect(pause.locator('[data-wjs-status-cell] .badge')).toHaveText('Paused');
    await expect(pause).toHaveClass(/table-active/);
  });

  test('the edit page of a step names the active run, and its form cannot be sent', async ({ page }) => {
    const id = await openWorkflowTemplate(page, LOCKED_WORKFLOW);
    const runId = await activeRunId(page);
    const stepId = await templateStepId(page, LOCKED_JOB);

    await page.goto(`/workflow-template/edit-step?id=${id}&step=${stepId}`);
    await expect(page.locator('#edit-step-locked')).toHaveText(runsActive(`#${runId}`));
    await expect(page.locator('#edit-step-locked a')).toHaveAttribute('href', `/workflow-job/view?id=${runId}`);
    // A disabled fieldset disables every field and button inside it.
    await expect(page.locator('#edit-step-fieldset')).toHaveAttribute('disabled', '');
    await expect(page.locator('#edit-step-form button[type="submit"]')).toBeDisabled();
    await expect(page.locator('#workflowstep-name')).toBeDisabled();
  });

  test('forged step changes are refused while the run is active, an invalid step first', async ({ page }) => {
    const id = await openWorkflowTemplate(page, LOCKED_WORKFLOW);
    const runId = await activeRunId(page);
    const pauseStepId = await templateStepId(page, LOCKED_PAUSE);
    const jobStepId = await templateStepId(page, LOCKED_JOB);

    // An invalid step is refused for what is wrong with it, before the run
    // is looked at. The form reads a JSON true as "1", which is no step type.
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const invalid = await page.request.post(`/workflow-template/add-step?id=${id}`, {
      headers: { 'X-CSRF-Token': csrf ?? '' },
      data: { WorkflowStep: { name: 'e2e-locked-invalid', step_type: true, step_order: 30 } },
    });
    expect(invalid.status()).toBe(200);
    expect(await invalid.text()).toContain(`${STEP_NOT_ADDED}Step Type is invalid.`);

    // Real form posts with a valid CSRF token: only the server checks may refuse them.
    const locked = runsActive(`#${runId}`);
    const changes: [string, Record<string, string>, string][] = [
      [
        `/workflow-template/add-step?id=${id}`,
        { 'WorkflowStep[name]': 'e2e-locked-new', 'WorkflowStep[step_type]': 'pause', 'WorkflowStep[step_order]': '30' },
        `${STEP_NOT_ADDED}${locked}`,
      ],
      [`/workflow-template/remove-step?id=${id}`, { step_id: jobStepId }, locked],
      [`/workflow-template/move-step?id=${id}`, { step_id: pauseStepId, direction: 'down' }, `${STEP_NOT_MOVED}${locked}`],
      [`/workflow-template/move-step?id=${id}`, { step_id: jobStepId, position: 'start' }, `${STEP_NOT_MOVED}${locked}`],
    ];
    for (const [action, fields, refusal] of changes) {
      await page.goto('/workflow-template/index');
      await forgePost(page, action, fields, /\/workflow-template\/view/);
      await expectFlash(page, 'danger', refusal);
    }

    // A forged save of the edit form is refused on the edit page itself.
    await page.goto('/workflow-template/index');
    await forgePost(page, `/workflow-template/edit-step?id=${id}&step=${jobStepId}`, {
      'WorkflowStep[name]': 'e2e-locked-renamed',
      position: 'keep',
    }, /\/workflow-template\/edit-step/);
    await expect(page.locator('#step-form-error')).toHaveText(`${STEP_NOT_SAVED}${locked}`);

    await openWorkflowTemplate(page, LOCKED_WORKFLOW);
    await expect(templateStepNames(page)).toHaveText([LOCKED_PAUSE, LOCKED_JOB]);
    expect(await activeRunId(page)).toBe(runId);
  });
});

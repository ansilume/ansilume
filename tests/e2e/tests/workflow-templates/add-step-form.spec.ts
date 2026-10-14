import { test, expect } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  ALWAYS_WINS_TITLE,
  APPROVAL_STEPS_JOB,
  APPROVAL_STEPS_WORKFLOW,
  END_FAILS_OPTION,
  END_SUCCEEDS_OPTION,
  FAILURE_DEFAULT_NOTE,
  JOB_TEMPLATE,
  NEXT_OPTION_NONE_FAILS,
  PAUSE_FAILURE_TITLE,
  POSITION_SELECT,
  ROUTE_END_SUCCEEDS,
  ROUTE_UNUSED,
  addStepFormErrors,
  nowEnds,
  positionEnd,
  removeTemplateStep,
  routeCell,
  routeLoop,
  stepAdded,
  stepLabel,
  stepRemoved,
  templateStepRow,
  typeBadge,
} from '../../lib/workflows';

/**
 * The add-step form takes a step of each type: the page offers only the
 * target and the routes of the chosen type, and the fields of the other
 * types must neither be checked nor posted. The step goes where Position
 * says, at the end by default.
 *
 * Regression: a required target select of another type, hidden but still
 * checked by the form's client-side validation, kept the form from being
 * sent: the job template ("A job step needs a job template.") for pause and
 * approval steps since 2.8.1, and, for admins, the approval rule ("An
 * approval step needs an approval rule.") for job and pause steps.
 *
 * Admins: here (approval steps: approval-steps.spec.ts); operators:
 * workflow-templates/rbac.spec.ts. Fixture: e2e-approval-steps-workflow,
 * never launched, with the one job step e2e-approval-steps-job
 * (commands/E2eWorkflowGateSeeder.php). Each test removes the step it added.
 */
const STEPS = '#workflow-steps-table tbody tr';

test.describe('Add steps through the form (admin)', () => {
  test('admin adds a job step through the form and removes it again', async ({ page }) => {
    const name = 'e2e-added-job';
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    await expect(page.locator(STEPS)).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(name);
    await page.locator('#ws-step-type').selectOption('job');
    // Position stays as the form shows it: at the end.
    await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveText(positionEnd(stepLabel(1, APPROVAL_STEPS_JOB)));
    await page.locator('#workflowstep-job_template_id').selectOption({ label: JOB_TEMPLATE });
    await page.locator('#workflowstep-on_failure_step_id').selectOption({ label: END_FAILS_OPTION });
    await page.locator('#workflowstep-on_always_step_id').selectOption({ label: stepLabel(1, APPROVAL_STEPS_JOB) });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', stepAdded(name, 2, 2, APPROVAL_STEPS_JOB));
    await expect(page.locator(STEPS)).toHaveCount(2);
    const added = templateStepRow(page, name);
    await expect(added.locator('[data-position]')).toHaveText('2');
    await expect(typeBadge(added)).toHaveText('Job');
    await expect(added.locator('td').nth(3)).toHaveText(JOB_TEMPLATE);
    // Always is taken after a success and after a failure: the other two are not used.
    await expect(routeCell(added, 'success')).toHaveText(ROUTE_UNUSED);
    await expect(routeCell(added, 'success').locator('span')).toHaveAttribute('title', ALWAYS_WINS_TITLE);
    await expect(routeCell(added, 'failure')).toHaveText(ROUTE_UNUSED);
    await expect(routeCell(added, 'always')).toHaveText(routeLoop(1, APPROVAL_STEPS_JOB));

    await removeTemplateStep(page, name);
    await expectFlash(page, 'success', `${stepRemoved(name, 2, 2)} ${nowEnds(APPROVAL_STEPS_JOB, name)}`);
    await expect(page.locator(STEPS)).toHaveCount(1);
  });

  test('admin adds a pause step through the form and removes it again', async ({ page }) => {
    const name = 'e2e-added-pause';
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    await expect(page.locator(STEPS)).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(name);
    await page.locator('#ws-step-type').selectOption('pause');
    await expect(page.locator(POSITION_SELECT)).toHaveValue('end');
    await page.locator('#workflowstep-on_success_step_id').selectOption({ label: END_SUCCEEDS_OPTION });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', stepAdded(name, 2, 2, APPROVAL_STEPS_JOB));
    await expect(page.locator(STEPS)).toHaveCount(2);
    const added = templateStepRow(page, name);
    await expect(typeBadge(added)).toHaveText('Pause');
    await expect(added.locator('td').nth(3)).toHaveText('—');
    await expect(routeCell(added, 'success')).toHaveText(ROUTE_END_SUCCEEDS);
    // A pause step never fails: Resume continues with On Success.
    await expect(routeCell(added, 'failure')).toHaveText(ROUTE_UNUSED);
    await expect(routeCell(added, 'failure').locator('span')).toHaveAttribute('title', PAUSE_FAILURE_TITLE);
    await expect(routeCell(added, 'always')).toHaveText(ROUTE_UNUSED);

    await removeTemplateStep(page, name);
    await expect(page.locator(STEPS)).toHaveCount(1);
  });

  // W2-T1 checked the page script by hand only. A new job step ends the
  // workflow when it fails; an approval step's empty On Failure already
  // ends the run as failed, so its second end choice is taken out (Safari
  // ignores hidden options) and put back for the other types.
  test('switching the type takes the second end choice of On Failure out and puts it back', async ({ page }) => {
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const type = page.locator('#ws-step-type');
    const failure = page.locator('#workflowstep-on_failure_step_id');
    const firstChoices = failure.locator('option').nth(1);
    const note = page.locator('#ws-failure-default-note');

    // A job step, as the form starts: On Failure ends the workflow.
    await expect(failure).toHaveValue('0');
    await expect(failure.locator('option[value=""]')).toHaveText(NEXT_OPTION_NONE_FAILS);
    await expect(firstChoices).toHaveText(END_FAILS_OPTION);
    await expect(note).toHaveText(FAILURE_DEFAULT_NOTE);
    await expect(note).toBeVisible();

    await type.selectOption('approval');
    await expect(failure.locator('option[value="0"]')).toHaveCount(0);
    await expect(failure.locator('option[value=""]')).toHaveText(END_FAILS_OPTION);
    await expect(failure).toHaveValue('');
    await expect(note).toBeHidden();

    // Back to a job step: the end choice is back, right after the empty
    // one, and selected again.
    await type.selectOption('job');
    await expect(failure.locator('option[value="0"]')).toHaveCount(1);
    await expect(firstChoices).toHaveAttribute('value', '0');
    await expect(failure).toHaveValue('0');
    await expect(failure.locator('option[value=""]')).toHaveText(NEXT_OPTION_NONE_FAILS);
    await expect(note).toBeVisible();

    // A pause step has no On Failure; back to a job step, it ends the workflow again.
    await type.selectOption('pause');
    await expect(failure).toBeDisabled();
    await type.selectOption('job');
    await expect(failure).toBeEnabled();
    await expect(failure).toHaveValue('0');
  });

  test('an On Failure the user chose is kept when the type changes and back', async ({ page }) => {
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const type = page.locator('#ws-step-type');
    const failure = page.locator('#workflowstep-on_failure_step_id');

    // Go on with the next step instead of ending the workflow.
    await failure.selectOption({ label: NEXT_OPTION_NONE_FAILS });
    await type.selectOption('approval');
    await type.selectOption('job');
    await expect(failure).toHaveValue('');

    // A step of the workflow.
    await failure.selectOption({ label: stepLabel(1, APPROVAL_STEPS_JOB) });
    await type.selectOption('pause');
    await type.selectOption('job');
    await expect(failure.locator('option:checked')).toHaveText(stepLabel(1, APPROVAL_STEPS_JOB));
  });

  // regression: back on Job, the script restored the On Failure the form
  // had when it last left the job type. A step chosen while the type was
  // Approval became "End the workflow: the run fails" ("0") again, also
  // through Pause, and an end chosen as an approval step became the step
  // chosen before as a job step. It keeps the latest choice now; an
  // approval step's empty choice ends the run as failed, "0" for a job
  // step. Each case starts with a fresh form.
  test('regression: an On Failure chosen while the type was Approval is kept', async ({ page }) => {
    const id = await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const type = page.locator('#ws-step-type');
    const failure = page.locator('#workflowstep-on_failure_step_id');
    const step = stepLabel(1, APPROVAL_STEPS_JOB);
    const freshForm = async () => {
      await page.goto(`/workflow-template/view?id=${id}`);
      await expect(type).toHaveValue('job');
      await expect(failure).toHaveValue('0');
    };

    // (1) Approval, a step, back to Job: that step.
    await freshForm();
    await type.selectOption('approval');
    await failure.selectOption({ label: step });
    await type.selectOption('job');
    await expect(failure.locator('option:checked')).toHaveText(step);

    // (2) Approval, a step, Pause, Job: that step.
    await freshForm();
    await type.selectOption('approval');
    await failure.selectOption({ label: step });
    await type.selectOption('pause');
    await type.selectOption('job');
    await expect(failure.locator('option:checked')).toHaveText(step);

    // (3) The step as a job step, Approval, the end choice, Job: "0".
    await freshForm();
    await failure.selectOption({ label: step });
    await type.selectOption('approval');
    await expect(failure.locator('option:checked')).toHaveText(step);
    await failure.selectOption({ label: END_FAILS_OPTION });
    await expect(failure).toHaveValue('');
    await type.selectOption('job');
    await expect(failure).toHaveValue('0');
    await expect(failure.locator('option:checked')).toHaveText(END_FAILS_OPTION);
  });
});

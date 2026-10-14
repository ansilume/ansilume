import { test, expect, Locator, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { forgePost, idWhere, indexRows } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  APPROVAL_STEPS_JOB,
  APPROVAL_STEPS_WORKFLOW,
  GATED_BADGE,
  GATED_JOB_AFTER,
  GATED_JOB_STEP,
  GATED_JOB_TEMPLATE,
  GATED_JOB_WORKFLOW,
  GATE_CONTINUE_APPROVE,
  GATE_CONTINUE_JOB,
  GATE_CONTINUE_WORKFLOW,
  GATE_RULE,
  NEXT_TITLE,
  PART_SEPARATOR,
  PAUSE_FAILURE_TITLE,
  POSITION_SELECT,
  REFUSED_APPROVAL_TITLE,
  ROUTE_AFTER_REFUSED_APPROVAL,
  ROUTE_ALSO_AFTER_FAILED_JOB,
  ROUTE_END_FAILS,
  ROUTE_END_SUCCEEDS,
  ROUTE_UNUSED,
  STEPS_BUILD,
  STEPS_LOCKED_NOTICE,
  STEPS_WORKFLOW,
  STEP_NOT_ADDED,
  addStepFormErrors,
  gatedTitle,
  nowEnds,
  removeTemplateStep,
  routeCell,
  routeNext,
  stepAdded,
  stepLabel,
  stepRemoved,
  templateStepId,
  templateStepRow,
  typeBadge,
} from '../../lib/workflows';

/**
 * Approval steps, and job steps whose job template has an approval rule
 * ("gated job steps"), are for admins (approval-rule.update); e2e-admin is
 * one. Operators: workflow-templates/rbac.spec.ts. Fixtures
 * (commands/E2eWorkflowGateSeeder.php): e2e-approval-steps-workflow, never
 * launched, with the one job step e2e-approval-steps-job; and
 * e2e-gate-continue-workflow, whose approval step has no On Failure branch.
 *
 * Other specs leave runs waiting at these workflows' steps, and the Type
 * cell also counts them: only the type badge is asserted.
 */
const ADDED = 'e2e-added-approval';
const ADDED_GATED_JOB = 'e2e-added-gated-job';

/** The ID of e2e-gate-rule, from the approval rule index (columns: Name, ...). */
async function gateRuleId(page: Page): Promise<string> {
  return idWhere(await indexRows(page, '/approval-rule/index'), 0, GATE_RULE);
}

/** The hover texts of the parts of a route cell. */
async function partTitles(row: Locator, route: 'success' | 'failure' | 'always'): Promise<(string | null)[]> {
  return routeCell(row, route).locator(':scope > span').evaluateAll((spans) => spans.map((span) => span.getAttribute('title')));
}

test.describe('Approval steps (admin)', () => {
  // A rejected, timed-out or canceled approval fails closed: without an On
  // Failure branch the run ends as failed instead of going on with the next
  // step, and an approval step has no Always branch.
  test('the routes of an approval step without On Failure branch say that a rejection ends the run', async ({ page }) => {
    await openWorkflowTemplate(page, GATE_CONTINUE_WORKFLOW);
    const approve = templateStepRow(page, GATE_CONTINUE_APPROVE);
    await expect(typeBadge(approve)).toHaveText('Approval');
    await expect(approve.locator('td').nth(3)).toHaveText(GATE_RULE);
    await expect(routeCell(approve, 'success')).toHaveText(routeNext(2, GATE_CONTINUE_JOB));
    await expect(routeCell(approve, 'failure')).toHaveText(ROUTE_END_FAILS);
    await expect(routeCell(approve, 'always')).toHaveText(ROUTE_UNUSED);
    // The job step is the last: its empty branches end the run with its outcome.
    const job = templateStepRow(page, GATE_CONTINUE_JOB);
    await expect(typeBadge(job)).toHaveText('Job');
    await expect(routeCell(job, 'success')).toHaveText(ROUTE_END_SUCCEEDS);
    await expect(routeCell(job, 'failure')).toHaveText(ROUTE_END_FAILS);
    await expect(routeCell(job, 'always')).toHaveText(ROUTE_UNUSED);
  });

  // A job step whose job template has an approval rule fails closed too:
  // when its approval is rejected, times out or is canceled, the job never
  // runs, and an empty On Failure ends the run as failed instead of going on
  // with the next step; a job that ran and failed still goes on with the next
  // step. Regression: the steps table said only "→ next step".
  test('the empty On Failure of a job step whose job template needs approval says when the run ends as failed', async ({ page }) => {
    await openWorkflowTemplate(page, GATED_JOB_WORKFLOW);
    const gated = templateStepRow(page, GATED_JOB_STEP);
    await expect(typeBadge(gated)).toHaveText('Job');
    await expect(gated.locator('[data-gated-badge]')).toHaveText(GATED_BADGE);
    await expect(gated.locator('[data-gated-badge]')).toHaveAttribute('title', gatedTitle(GATE_RULE));
    await expect(gated.locator('td').nth(3)).toHaveText(GATED_JOB_TEMPLATE);
    await expect(routeCell(gated, 'success')).toHaveText(routeNext(2, GATED_JOB_AFTER));
    // Both cases: a job that ran and failed, and an approval that was refused.
    await expect(routeCell(gated, 'failure')).toHaveText(
      `${routeNext(2, GATED_JOB_AFTER)}${ROUTE_ALSO_AFTER_FAILED_JOB}${PART_SEPARATOR}${ROUTE_END_FAILS}${ROUTE_AFTER_REFUSED_APPROVAL}`,
    );
    expect(await partTitles(gated, 'failure')).toEqual([NEXT_TITLE, REFUSED_APPROVAL_TITLE]);
    await expect(routeCell(gated, 'always')).toHaveText(ROUTE_UNUSED);
    // The pause step after it is no gate, and never fails.
    const pause = templateStepRow(page, GATED_JOB_AFTER);
    await expect(typeBadge(pause)).toHaveText('Pause');
    await expect(routeCell(pause, 'failure')).toHaveText(ROUTE_UNUSED);
    expect(await partTitles(pause, 'failure')).toEqual([PAUSE_FAILURE_TITLE]);
  });

  test('admin adds an approval step and removes it again', async ({ page }) => {
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const steps = page.locator('#workflow-steps-table tbody tr');
    await expect(steps).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(ADDED);
    await expect(page.locator(POSITION_SELECT)).toHaveValue('end');
    // An Always branch chosen while the step was a job step is not posted
    // once it is an approval step: the server would refuse the step. The
    // hidden job template select must not keep the form from being sent
    // either (add-step-form.spec.ts).
    await page.locator('#ws-step-type').selectOption('job');
    await page.locator('#workflowstep-on_always_step_id').selectOption({ label: stepLabel(1, APPROVAL_STEPS_JOB) });
    await page.locator('#ws-step-type').selectOption('approval');
    await page.locator('#workflowstep-approval_rule_id').selectOption({ label: GATE_RULE });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', `${stepAdded(ADDED, 2, 2, APPROVAL_STEPS_JOB)} "${APPROVAL_STEPS_JOB}" now continues with `
      + `"${ADDED}", also after a failure (was the end of the run).`);
    await expect(steps).toHaveCount(2);
    const added = templateStepRow(page, ADDED);
    await expect(added.locator('[data-position]')).toHaveText('2');
    await expect(typeBadge(added)).toHaveText('Approval');
    await expect(added.locator('td').nth(3)).toHaveText(GATE_RULE);
    await expect(routeCell(added, 'success')).toHaveText(ROUTE_END_SUCCEEDS);
    // Without an On Failure branch, a rejection ends the run as failed.
    await expect(routeCell(added, 'failure')).toHaveText(ROUTE_END_FAILS);
    await expect(routeCell(added, 'always')).toHaveText(ROUTE_UNUSED);
    // An admin may still change the steps of a workflow with an approval step.
    await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toBeVisible();

    await removeTemplateStep(page, ADDED);
    await expectFlash(page, 'success', `${stepRemoved(ADDED, 2, 2)} ${nowEnds(APPROVAL_STEPS_JOB, ADDED)}`);
    await expect(steps).toHaveCount(1);
    await expect(templateStepRow(page, APPROVAL_STEPS_JOB)).toHaveCount(1);
  });

  // Operators are not offered such job templates, and a forged step of theirs
  // is refused (workflow-templates/rbac.spec.ts).
  test('admin adds a job step whose job template needs approval and removes it again', async ({ page }) => {
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const steps = page.locator('#workflow-steps-table tbody tr');
    await expect(steps).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(ADDED_GATED_JOB);
    await expect(page.locator(POSITION_SELECT)).toHaveValue('end');
    await page.locator('#ws-step-type').selectOption('job');
    await page.locator('#workflowstep-job_template_id').selectOption({ label: GATED_JOB_TEMPLATE });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', stepAdded(ADDED_GATED_JOB, 2, 2, APPROVAL_STEPS_JOB));
    await expect(steps).toHaveCount(2);
    const added = templateStepRow(page, ADDED_GATED_JOB);
    await expect(added.locator('[data-position]')).toHaveText('2');
    await expect(typeBadge(added)).toHaveText('Job');
    await expect(added.locator('[data-gated-badge]')).toHaveText(GATED_BADGE);
    await expect(added.locator('td').nth(3)).toHaveText(GATED_JOB_TEMPLATE);
    await expect(routeCell(added, 'success')).toHaveText(ROUTE_END_SUCCEEDS);
    // The form's default ends the workflow when the step fails; a refused
    // approval ends it as failed too: one part.
    await expect(routeCell(added, 'failure')).toHaveText(ROUTE_END_FAILS);
    await expect(routeCell(added, 'always')).toHaveText(ROUTE_UNUSED);
    // An admin may still change the steps of a workflow with a gated job step.
    await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toBeVisible();

    await removeTemplateStep(page, ADDED_GATED_JOB);
    await expect(steps).toHaveCount(1);
    await expect(templateStepRow(page, APPROVAL_STEPS_JOB)).toHaveCount(1);
  });

  // The forged posts send a step_order, which the form never reads: only the
  // refusals count.
  test('an approval step without a rule or with an Always branch, and a branch out of the workflow, are refused', async ({ page }) => {
    const ruleId = await gateRuleId(page);
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    const foreignStepId = await templateStepId(page, STEPS_BUILD);
    const id = await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const jobStepId = await templateStepId(page, APPROVAL_STEPS_JOB);
    const approvalStep = (fields: Record<string, string>) => ({
      'WorkflowStep[name]': ADDED,
      'WorkflowStep[step_type]': 'approval',
      'WorkflowStep[step_order]': '20',
      ...fields,
    });
    const refusals: [Record<string, string>, string][] = [
      [
        // A branch leads to the end or to a step of the same workflow.
        { 'WorkflowStep[name]': ADDED, 'WorkflowStep[step_type]': 'pause', 'WorkflowStep[step_order]': '20', 'WorkflowStep[on_success_step_id]': foreignStepId },
        'The selected step is not part of this workflow.',
      ],
      [approvalStep({}), 'An approval step needs an approval rule.'],
      [approvalStep({ 'WorkflowStep[approval_rule_id]': '999999999' }), 'The selected approval rule does not exist.'],
      [
        approvalStep({ 'WorkflowStep[approval_rule_id]': ruleId, 'WorkflowStep[on_always_step_id]': jobStepId }),
        'An approval step cannot have an Always branch: a rejection would continue past it.',
      ],
      [
        // END is an Always branch too.
        approvalStep({ 'WorkflowStep[approval_rule_id]': ruleId, 'WorkflowStep[on_always_step_id]': '0' }),
        'An approval step cannot have an Always branch: a rejection would continue past it.',
      ],
    ];

    for (const [fields, refusal] of refusals) {
      // Real form posts with a valid CSRF token: only the server checks may refuse them.
      await page.goto('/workflow-template/index');
      await forgePost(page, `/workflow-template/add-step?id=${id}`, fields, /\/workflow-template\/view/);
      await expect(page).toHaveURL(/#add-step$/);
      await expectFlash(page, 'danger', `${STEP_NOT_ADDED}${refusal}`);
      await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(1);
      await expect(templateStepRow(page, ADDED)).toHaveCount(0);
    }
  });
});

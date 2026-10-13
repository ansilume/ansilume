import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { forgePost, idWhere, indexRows } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  APPROVAL_STEPS_JOB,
  APPROVAL_STEPS_WORKFLOW,
  GATED_JOB_AFTER,
  GATED_JOB_FAILURE_ROUTE,
  GATED_JOB_FAILURE_TITLE,
  GATED_JOB_STEP,
  GATED_JOB_TEMPLATE,
  GATED_JOB_WORKFLOW,
  GATE_CONTINUE_APPROVE,
  GATE_CONTINUE_JOB,
  GATE_CONTINUE_WORKFLOW,
  GATE_RULE,
  ROUTE_END_FAILED,
  ROUTE_NEXT_STEP,
  STEPS_BUILD,
  STEPS_LOCKED_NOTICE,
  STEPS_WORKFLOW,
  addStepFormErrors,
  templateStepRow,
} from '../../lib/workflows';

/**
 * Approval steps, and job steps whose job template has an approval rule
 * ("gated job steps"), are for admins (approval-rule.update); e2e-admin is
 * one. Operators: workflow-templates/rbac.spec.ts. Fixtures
 * (commands/E2eWorkflowGateSeeder.php): e2e-approval-steps-workflow, never
 * launched, with the one job step e2e-approval-steps-job; and
 * e2e-gate-continue-workflow, whose approval step has no On Failure branch.
 */
const ADDED = 'e2e-added-approval';
const ADDED_GATED_JOB = 'e2e-added-gated-job';

/** The ID of e2e-gate-rule, from the approval rule index (columns: Name, ...). */
async function gateRuleId(page: Page): Promise<string> {
  return idWhere(await indexRows(page, '/approval-rule/index'), 0, GATE_RULE);
}

/** The ID of the step named `name` of the open workflow template page. */
async function stepId(page: Page, name: string): Promise<string> {
  const id = (await templateStepRow(page, name).getAttribute('data-step-id')) ?? '';
  expect(id, `the ID of step ${name}`).toMatch(/^\d+$/);
  return id;
}

test.describe('Approval steps (admin)', () => {
  // A rejected, timed-out or canceled approval fails closed: without an On
  // Failure branch the run ends as failed instead of going on with the next
  // step, and an approval step has no Always branch.
  test('the routes of an approval step without On Failure branch say that a rejection ends the run', async ({ page }) => {
    await openWorkflowTemplate(page, GATE_CONTINUE_WORKFLOW);
    const approve = templateStepRow(page, GATE_CONTINUE_APPROVE);
    await expect(approve.locator('td').nth(2)).toHaveText('Approval');
    await expect(approve.locator('td').nth(3)).toHaveText(GATE_RULE);
    await expect(approve.locator('td[data-route="success"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(approve.locator('td[data-route="failure"]')).toHaveText(ROUTE_END_FAILED);
    await expect(approve.locator('td[data-route="always"]')).toHaveText('—');
    // A job step's empty On Failure still goes on with the next step.
    const job = templateStepRow(page, GATE_CONTINUE_JOB);
    await expect(job.locator('td').nth(2)).toHaveText('Job');
    await expect(job.locator('td[data-route="failure"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(job.locator('td[data-route="always"]')).toHaveText('—');
  });

  // A job step whose job template has an approval rule fails closed too:
  // when its approval is rejected, times out or is canceled, the job never
  // runs, and an empty On Failure ends the run as failed instead of going on
  // with the next step; a job that ran and failed still goes on with the next
  // step. Regression: the steps table said only "→ next step".
  test('the empty On Failure of a job step whose job template needs approval says when the run ends as failed', async ({ page }) => {
    await openWorkflowTemplate(page, GATED_JOB_WORKFLOW);
    const gated = templateStepRow(page, GATED_JOB_STEP);
    await expect(gated.locator('td').nth(2)).toHaveText('Job');
    await expect(gated.locator('td').nth(3)).toHaveText(GATED_JOB_TEMPLATE);
    await expect(gated.locator('td[data-route="success"]')).toHaveText(ROUTE_NEXT_STEP);
    const failure = gated.locator('td[data-route="failure"]');
    // Both cases: a job that ran and failed, and an approval that was refused.
    await expect(failure).toHaveText(GATED_JOB_FAILURE_ROUTE);
    await expect(failure.locator('span')).toHaveAttribute('title', GATED_JOB_FAILURE_TITLE);
    await expect(gated.locator('td[data-route="always"]')).toHaveText('—');
    // The pause step after it is no gate.
    const pause = templateStepRow(page, GATED_JOB_AFTER);
    await expect(pause.locator('td').nth(2)).toHaveText('Pause');
    await expect(pause.locator('td[data-route="failure"]')).toHaveText(ROUTE_NEXT_STEP);
  });

  test('admin adds an approval step and removes it again', async ({ page }) => {
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const steps = page.locator('#workflow-steps-table tbody tr');
    await expect(steps).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(ADDED);
    await page.locator('#workflowstep-step_order').fill('20');
    // An Always branch chosen while the step was a job step is not posted
    // once it is an approval step: the server would refuse the step. The
    // hidden job template select must not keep the form from being sent
    // either (add-step-form.spec.ts).
    await page.locator('#ws-step-type').selectOption('job');
    await page.locator('#workflowstep-on_always_step_id').selectOption({ label: `#10 ${APPROVAL_STEPS_JOB}` });
    await page.locator('#ws-step-type').selectOption('approval');
    await page.locator('#workflowstep-approval_rule_id').selectOption({ label: GATE_RULE });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', `Step "${ADDED}" added.`);
    await expect(steps).toHaveCount(2);
    const added = templateStepRow(page, ADDED);
    await expect(added.locator('td').nth(0)).toHaveText('20');
    await expect(added.locator('td').nth(2)).toHaveText('Approval');
    await expect(added.locator('td').nth(3)).toHaveText(GATE_RULE);
    await expect(added.locator('td[data-route="success"]')).toHaveText(ROUTE_NEXT_STEP);
    // Without an On Failure branch, a rejection ends the run as failed.
    await expect(added.locator('td[data-route="failure"]')).toHaveText(ROUTE_END_FAILED);
    await expect(added.locator('td[data-route="always"]')).toHaveText('—');
    // An admin may still change the steps of a workflow with an approval step.
    await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await added.locator('form[action*="/workflow-template/remove-step"] button').click();
    await expectFlash(page, 'success', 'Step removed.');
    await expect(steps).toHaveCount(1);
    await expect(templateStepRow(page, ADDED)).toHaveCount(0);
    await expect(templateStepRow(page, APPROVAL_STEPS_JOB)).toHaveCount(1);
  });

  // Operators are not offered such job templates, and a forged step of theirs
  // is refused (workflow-templates/rbac.spec.ts).
  test('admin adds a job step whose job template needs approval and removes it again', async ({ page }) => {
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const steps = page.locator('#workflow-steps-table tbody tr');
    await expect(steps).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(ADDED_GATED_JOB);
    await page.locator('#workflowstep-step_order').fill('20');
    await page.locator('#ws-step-type').selectOption('job');
    await page.locator('#workflowstep-job_template_id').selectOption({ label: GATED_JOB_TEMPLATE });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', `Step "${ADDED_GATED_JOB}" added.`);
    await expect(steps).toHaveCount(2);
    const added = templateStepRow(page, ADDED_GATED_JOB);
    await expect(added.locator('td').nth(0)).toHaveText('20');
    await expect(added.locator('td').nth(2)).toHaveText('Job');
    await expect(added.locator('td').nth(3)).toHaveText(GATED_JOB_TEMPLATE);
    await expect(added.locator('td[data-route="success"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(added.locator('td[data-route="failure"]')).toHaveText(GATED_JOB_FAILURE_ROUTE);
    await expect(added.locator('td[data-route="always"]')).toHaveText('—');
    // An admin may still change the steps of a workflow with a gated job step.
    await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await added.locator('form[action*="/workflow-template/remove-step"] button').click();
    await expectFlash(page, 'success', 'Step removed.');
    await expect(steps).toHaveCount(1);
    await expect(templateStepRow(page, ADDED_GATED_JOB)).toHaveCount(0);
    await expect(templateStepRow(page, APPROVAL_STEPS_JOB)).toHaveCount(1);
  });

  test('an approval step without a rule or with an Always branch, and a branch out of the workflow, are refused', async ({ page }) => {
    const ruleId = await gateRuleId(page);
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    const foreignStepId = await stepId(page, STEPS_BUILD);
    const id = await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    const jobStepId = await stepId(page, APPROVAL_STEPS_JOB);
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
        'Step not added: The selected step is not part of this workflow.',
      ],
      [approvalStep({}), 'Step not added: An approval step needs an approval rule.'],
      [approvalStep({ 'WorkflowStep[approval_rule_id]': '999999999' }), 'Step not added: The selected approval rule does not exist.'],
      [
        approvalStep({ 'WorkflowStep[approval_rule_id]': ruleId, 'WorkflowStep[on_always_step_id]': jobStepId }),
        'Step not added: An approval step cannot have an Always branch: a rejection would continue past it.',
      ],
      [
        // END is an Always branch too.
        approvalStep({ 'WorkflowStep[approval_rule_id]': ruleId, 'WorkflowStep[on_always_step_id]': '0' }),
        'Step not added: An approval step cannot have an Always branch: a rejection would continue past it.',
      ],
    ];

    for (const [fields, refusal] of refusals) {
      // Real form posts with a valid CSRF token: only the server checks may refuse them.
      await page.goto('/workflow-template/index');
      await forgePost(page, `/workflow-template/add-step?id=${id}`, fields, /\/workflow-template\/view/);
      await expectFlash(page, 'danger', refusal);
      await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(1);
      await expect(templateStepRow(page, ADDED)).toHaveCount(0);
    }
  });
});

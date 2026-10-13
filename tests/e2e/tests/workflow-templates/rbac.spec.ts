import { test, expect, Page } from '@playwright/test';
import { expectFlash, expectForbidden, submitForm, templateId } from '../../lib/helpers';
import { asAdmin, forgePost, idWhere, indexRows, optionLabels } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import { APPROVAL_RULE, DUAL_TEMPLATE, GATED_TEMPLATE, HISTORY_TEMPLATE, SOLO_TEMPLATE, ruleLabel } from '../../lib/approvals';
import { setApprovalRule } from '../../lib/job-templates';
import { RUN_AS_TEMPLATE } from '../../lib/schedules';
import {
  APPROVAL_STEP_ADD_REFUSED,
  APPROVAL_WORKFLOW,
  APPROVAL_WORKFLOW_STEPS_REFUSED,
  EMPTY_WORKFLOW,
  GATED_JOB_AFTER,
  GATED_JOB_STEP,
  GATED_JOB_STEP_ADD_REFUSED,
  GATED_JOB_TEMPLATE,
  GATED_JOB_WORKFLOW,
  GATED_JOB_WORKFLOW_STEPS_REFUSED,
  GATED_TEMPLATES_HINT,
  GATE_RULE,
  LATE_GATE_JOB,
  LATE_GATE_TEMPLATE,
  LATE_GATE_WORKFLOW,
  LOCKED_WORKFLOW,
  OPERATOR_STEPS_JOB,
  OPERATOR_STEPS_WORKFLOW,
  STEPS_BUILD,
  STEPS_CLEANUP,
  STEPS_LOCKED_NOTICE,
  STEPS_WORKFLOW,
  activeRunId,
  addStepFormErrors,
  stepsLockedNotice,
  templateStepRow,
} from '../../lib/workflows';

/** The job template select of the add-step form. */
const STEP_TEMPLATE_SELECT = '#workflowstep-job_template_id';

/**
 * Job templates in e2e-project, which e2e-operator's team operates, that have
 * an approval rule (commands/E2eApprovalSeeder.php, E2eScheduleRunAsSeeder.php,
 * E2eWorkflowGateSeeder.php).
 */
const GATED_TEMPLATES = [GATED_TEMPLATE, SOLO_TEMPLATE, HISTORY_TEMPLATE, DUAL_TEMPLATE, RUN_AS_TEMPLATE, GATED_JOB_TEMPLATE];

/** The step names of the open workflow template page, in order. */
async function stepNames(page: Page): Promise<string[]> {
  return page.locator('#workflow-steps-table tbody tr td:nth-child(2)').allTextContents();
}

/** The ID of the step named `name` of the open workflow template page. */
async function stepId(page: Page, name: string): Promise<string> {
  const id = (await templateStepRow(page, name).getAttribute('data-step-id')) ?? '';
  expect(id, `the ID of step ${name}`).toMatch(/^\d+$/);
  return id;
}

test.describe('Workflow Templates RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/workflow-template/create');
    await expectForbidden(page);
  });

  test('viewer can view index', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer sees no step forms and gets 403 on a forged add-step', async ({ page }) => {
    // Without job steps a workflow belongs to no project: everyone may view it.
    const id = await openWorkflowTemplate(page, EMPTY_WORKFLOW);
    await expect(page.locator('#add-step-form')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/delete"]')).toHaveCount(0);

    // A real form post with a valid CSRF token: only the permission check may stop it.
    await forgePost(page, `/workflow-template/add-step?id=${id}`, {
      'WorkflowStep[name]': 'e2e-viewer-step',
      'WorkflowStep[step_type]': 'pause',
      'WorkflowStep[step_order]': '10',
    }, /\/workflow-template\/add-step/);
    await expectForbidden(page);
    await openWorkflowTemplate(page, EMPTY_WORKFLOW);
    await expect(page.locator('#page-content')).toContainText('No steps defined yet.');
  });

  test('operator can create workflow templates', async ({ page }) => {
    await page.goto('/workflow-template/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator is not offered approval steps on a workflow without one', async ({ page }) => {
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    await expect(page.locator('#add-step-form')).toBeVisible();
    await expect(page.locator('#ws-step-type option')).toHaveText(['Job', 'Pause']);
    await expect(page.locator('#ws-target-approval')).toHaveCount(0);
    await expect(page.locator('#workflowstep-approval_rule_id')).toHaveCount(0);
    await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveCount(0);
  });

  // Job steps whose job template has an approval rule ("gated job steps")
  // are for admins too: a run launches its jobs as whoever started it, so an
  // operator who added one to another user's workflow could approve its job.
  test('operator is not offered job templates that have an approval rule for a job step', async ({ page, browser }, testInfo) => {
    await openWorkflowTemplate(page, OPERATOR_STEPS_WORKFLOW);
    const offered = await optionLabels(page, STEP_TEMPLATE_SELECT);
    expect(offered).toEqual(expect.arrayContaining(['e2e-template', LATE_GATE_TEMPLATE]));
    for (const gated of GATED_TEMPLATES) {
      expect(offered, `${gated} has an approval rule`).not.toContain(gated);
    }
    await expect(page.locator('#ws-gated-templates-hint')).toHaveText(GATED_TEMPLATES_HINT);

    // An admin (approval-rule.update) is offered them in the same form.
    const offeredToAdmin = await asAdmin(browser, testInfo, async (admin) => {
      await openWorkflowTemplate(admin, OPERATOR_STEPS_WORKFLOW);
      await expect(admin.locator('#ws-gated-templates-hint')).toHaveCount(0);
      return optionLabels(admin, STEP_TEMPLATE_SELECT);
    });
    expect(offeredToAdmin).toEqual(expect.arrayContaining(['e2e-template', LATE_GATE_TEMPLATE, ...GATED_TEMPLATES]));
  });

  // Regression: an operator could add a gated job step to another user's workflow and approve its job.
  test('operator gets 403 on a forged job step whose job template has an approval rule, and nothing is added', async ({ page }) => {
    // The operator may see the template, so its ID is no secret: the job list filters by it.
    const gatedId = await templateId(page, GATED_JOB_TEMPLATE);
    const id = await openWorkflowTemplate(page, STEPS_WORKFLOW);
    const before = await stepNames(page);
    expect(before).toEqual([STEPS_BUILD, STEPS_CLEANUP]);

    // A real form post with a valid CSRF token: only the server checks may stop it.
    await forgePost(page, `/workflow-template/add-step?id=${id}`, {
      'WorkflowStep[name]': 'e2e-operator-gated-job',
      'WorkflowStep[step_type]': 'job',
      'WorkflowStep[step_order]': '30',
      'WorkflowStep[job_template_id]': gatedId,
    }, /\/workflow-template\/add-step/);
    await expectForbidden(page);
    await expect(page.locator('body')).toContainText(GATED_JOB_STEP_ADD_REFUSED);

    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    expect(await stepNames(page)).toEqual(before);
    await expect(templateStepRow(page, 'e2e-operator-gated-job')).toHaveCount(0);
  });

  test('operator sees the steps of a workflow with a job step whose job template has an approval rule locked', async ({ page }) => {
    await openWorkflowTemplate(page, GATED_JOB_WORKFLOW);
    const notice = page.locator(STEPS_LOCKED_NOTICE);
    await expect(notice).toBeVisible();
    await expect(notice).toHaveText(stepsLockedNotice(GATED_JOB_WORKFLOW_STEPS_REFUSED));
    await expect(page.locator('#add-step-form')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/move-step"]')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/remove-step"]')).toHaveCount(0);
    // Name and description, launching and the trigger token stay open.
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
    await expect(page.locator('#page-content form[action*="/workflow-template/launch"] button')).toBeVisible();
  });

  test('operator gets 403 on forged step changes of a workflow with a job step whose job template has an approval rule', async ({ page }) => {
    const id = await openWorkflowTemplate(page, GATED_JOB_WORKFLOW);
    const before = await stepNames(page);
    expect(before).toEqual([GATED_JOB_STEP, GATED_JOB_AFTER]);
    const pauseStepId = await stepId(page, GATED_JOB_AFTER);

    // Real form posts with a valid CSRF token: only the server checks may refuse them.
    const changes: [string, Record<string, string>, RegExp][] = [
      [
        `/workflow-template/add-step?id=${id}`,
        { 'WorkflowStep[name]': 'e2e-operator-step', 'WorkflowStep[step_type]': 'pause', 'WorkflowStep[step_order]': '30' },
        /\/workflow-template\/add-step/,
      ],
      [`/workflow-template/remove-step?id=${id}`, { step_id: pauseStepId }, /\/workflow-template\/remove-step/],
      [`/workflow-template/move-step?id=${id}`, { step_id: pauseStepId, direction: 'up' }, /\/workflow-template\/move-step/],
    ];
    for (const [action, fields, landsOn] of changes) {
      await page.goto('/workflow-template/index');
      await forgePost(page, action, fields, landsOn);
      await expectForbidden(page);
      await expect(page.locator('body')).toContainText(GATED_JOB_WORKFLOW_STEPS_REFUSED);
    }

    await openWorkflowTemplate(page, GATED_JOB_WORKFLOW);
    expect(await stepNames(page)).toEqual(before);
  });

  // The lock is read when the page is asked for: a job template that gets an
  // approval rule locks the workflows that use it from then on.
  test('operator finds the steps of a workflow locked once the job template of one of its steps gets an approval rule', async ({ page, browser }, testInfo) => {
    await openWorkflowTemplate(page, LATE_GATE_WORKFLOW);
    expect(await stepNames(page)).toEqual([LATE_GATE_JOB]);
    await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toBeVisible();

    const lateGateId = await asAdmin(browser, testInfo, async (admin) => {
      const templateOf = await templateId(admin, LATE_GATE_TEMPLATE);
      await setApprovalRule(admin, templateOf, ruleLabel(APPROVAL_RULE));
      return templateOf;
    });
    try {
      await openWorkflowTemplate(page, LATE_GATE_WORKFLOW);
      await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveText(stepsLockedNotice(GATED_JOB_WORKFLOW_STEPS_REFUSED));
      await expect(page.locator('#add-step-form')).toHaveCount(0);
      await expect(page.locator('#page-content form[action*="/workflow-template/remove-step"]')).toHaveCount(0);
    } finally {
      await asAdmin(browser, testInfo, async (admin) => setApprovalRule(admin, lateGateId, ''));
    }

    // Without the rule, the steps are the operator's to change again.
    await openWorkflowTemplate(page, LATE_GATE_WORKFLOW);
    await expect(page.locator(STEPS_LOCKED_NOTICE)).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toBeVisible();
  });

  // The form must take the steps an operator may add: a required target
  // select of another type, hidden but still checked by the form, kept it
  // from being sent (admins: workflow-templates/add-step-form.spec.ts).
  for (const type of ['job', 'pause'] as const) {
    test(`operator adds a ${type} step through the form and removes it again`, async ({ page }) => {
      const name = `e2e-operator-added-${type}`;
      await openWorkflowTemplate(page, OPERATOR_STEPS_WORKFLOW);
      await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(1);

      await page.locator('#workflowstep-name').fill(name);
      await page.locator('#ws-step-type').selectOption(type);
      await page.locator('#workflowstep-step_order').fill('20');
      if (type === 'job') {
        await page.locator('#workflowstep-job_template_id').selectOption({ label: 'e2e-template' });
      }
      await submitForm(page, 'Add Step');
      // The form was sent: no field, hidden or not, refused it.
      expect(await addStepFormErrors(page)).toEqual([]);

      await expectFlash(page, 'success', `Step "${name}" added.`);
      const added = templateStepRow(page, name);
      await expect(added.locator('td').nth(2)).toHaveText(type === 'job' ? 'Job' : 'Pause');
      await expect(added.locator('td').nth(3)).toHaveText(type === 'job' ? 'e2e-template' : '—');
      expect(await stepNames(page)).toEqual([OPERATOR_STEPS_JOB, name]);

      page.once('dialog', (dialog) => dialog.accept());
      await added.locator('form[action*="/workflow-template/remove-step"] button').click();
      await expectFlash(page, 'success', 'Step removed.');
      expect(await stepNames(page)).toEqual([OPERATOR_STEPS_JOB]);
    });
  }

  test('operator gets 403 on a forged approval step, and nothing is added', async ({ page, browser }, testInfo) => {
    const ruleId = await asAdmin(browser, testInfo, async (admin) => idWhere(await indexRows(admin, '/approval-rule/index'), 0, GATE_RULE));
    const id = await openWorkflowTemplate(page, STEPS_WORKFLOW);
    const before = await stepNames(page);
    expect(before).toEqual([STEPS_BUILD, STEPS_CLEANUP]);

    // A real form post with a valid CSRF token: only the server checks may stop it.
    await forgePost(page, `/workflow-template/add-step?id=${id}`, {
      'WorkflowStep[name]': 'e2e-operator-approval',
      'WorkflowStep[step_type]': 'approval',
      'WorkflowStep[step_order]': '30',
      'WorkflowStep[approval_rule_id]': ruleId,
    }, /\/workflow-template\/add-step/);
    await expectForbidden(page);
    await expect(page.locator('body')).toContainText(APPROVAL_STEP_ADD_REFUSED);

    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    expect(await stepNames(page)).toEqual(before);
  });

  test('operator sees the steps of a workflow with an approval step locked', async ({ page }) => {
    await openWorkflowTemplate(page, APPROVAL_WORKFLOW);
    const notice = page.locator(STEPS_LOCKED_NOTICE);
    await expect(notice).toBeVisible();
    await expect(notice).toHaveText(stepsLockedNotice(APPROVAL_WORKFLOW_STEPS_REFUSED));
    await expect(page.locator('#add-step-form')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/move-step"]')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/remove-step"]')).toHaveCount(0);
    // Name and description, launching and the trigger token stay open.
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
    await expect(page.locator('#page-content form[action*="/workflow-template/launch"] button')).toBeVisible();
  });

  test('operator gets 403 on forged step changes of a workflow with an approval step', async ({ page }) => {
    const id = await openWorkflowTemplate(page, APPROVAL_WORKFLOW);
    const before = await stepNames(page);
    expect(before).toContain('e2e-step-approve');
    const jobStepId = await stepId(page, 'e2e-step-job-after-approval');

    // Real form posts with a valid CSRF token: only the server checks may refuse them.
    const changes: [string, Record<string, string>, RegExp][] = [
      [
        `/workflow-template/add-step?id=${id}`,
        { 'WorkflowStep[name]': 'e2e-operator-step', 'WorkflowStep[step_type]': 'pause', 'WorkflowStep[step_order]': '30' },
        /\/workflow-template\/add-step/,
      ],
      [`/workflow-template/remove-step?id=${id}`, { step_id: jobStepId }, /\/workflow-template\/remove-step/],
      [`/workflow-template/move-step?id=${id}`, { step_id: jobStepId, direction: 'up' }, /\/workflow-template\/move-step/],
    ];
    for (const [action, fields, landsOn] of changes) {
      await page.goto('/workflow-template/index');
      await forgePost(page, action, fields, landsOn);
      await expectForbidden(page);
      await expect(page.locator('body')).toContainText(APPROVAL_WORKFLOW_STEPS_REFUSED);
    }

    await openWorkflowTemplate(page, APPROVAL_WORKFLOW);
    expect(await stepNames(page)).toEqual(before);
  });

  // The active-run lock holds for everyone who may change steps (admins:
  // workflow-templates/active-run-lock.spec.ts).
  test('operator sees the steps of a workflow with an active run locked, and a forged step is refused', async ({ page }) => {
    const id = await openWorkflowTemplate(page, LOCKED_WORKFLOW);
    const runId = await activeRunId(page);
    const runsActive = `Steps cannot change while run(s) #${runId} of this workflow are active: wait for them to finish or cancel them.`;
    await expect(page.locator('#wf-active-runs-notice')).toHaveText(runsActive);
    await expect(page.locator('#add-step-form')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/workflow-template/remove-step"]')).toHaveCount(0);
    const before = await stepNames(page);

    // A real form post with a valid CSRF token: only the server checks may refuse it.
    await page.goto('/workflow-template/index');
    await forgePost(page, `/workflow-template/add-step?id=${id}`, {
      'WorkflowStep[name]': 'e2e-operator-locked-step',
      'WorkflowStep[step_type]': 'pause',
      'WorkflowStep[step_order]': '30',
    }, /\/workflow-template\/view/);
    await expectFlash(page, 'danger', `Step not added: ${runsActive}`);
    expect(await stepNames(page)).toEqual(before);
  });

  test('operator is offered no Delete and gets 403 on a forged delete', async ({ page }) => {
    const id = await openWorkflowTemplate(page, STEPS_WORKFLOW);
    await expect(page.locator('#wf-delete-form')).toHaveCount(0);

    await forgePost(page, `/workflow-template/delete?id=${id}`, {}, /\/workflow-template\/delete/);
    await expectForbidden(page);
    expect(await openWorkflowTemplate(page, STEPS_WORKFLOW)).toBe(id);
  });
});

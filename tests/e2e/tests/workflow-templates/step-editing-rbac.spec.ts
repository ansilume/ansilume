import { test, expect, Page } from '@playwright/test';
import { expectForbidden, templateId } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  APPROVAL_STEP_SUFFIX,
  APPROVAL_WORKFLOW,
  APPROVAL_WORKFLOW_STEPS_REFUSED,
  EDIT_OPERATOR_FIRST,
  EDIT_OPERATOR_SECOND,
  EDIT_OPERATOR_WORKFLOW,
  GATED_JOB_AFTER,
  GATED_JOB_STEP_ADD_REFUSED,
  GATED_JOB_TEMPLATE,
  GATED_JOB_WORKFLOW,
  GATED_JOB_WORKFLOW_STEPS_REFUSED,
  JOB_TEMPLATE,
  LEGEND,
  POSITION_SELECT,
  ROUTES_WORKFLOW,
  ROUTE_END_SUCCEEDS,
  START_BADGE,
  STEPS_COLUMNS,
  STEPS_LOCKED_NOTICE,
  addStepThroughForm,
  editStepThroughForm,
  expectWarningFlash,
  removingSteps,
  startChanged,
  stepAdded,
  stepLabel,
  stepMoved,
  stepSaved,
  stepsLockedNotice,
  successPath,
  templateStepId,
  templateStepNames,
  templateStepPositions,
  templateStepRow,
} from '../../lib/workflows';

/**
 * Who may edit, move and insert workflow steps. Every step change needs
 * workflow-template.update and operator access to the project of every job
 * step; the steps of a workflow with an approval step or a gated job step
 * (one whose job template has an approval rule) are for admins only
 * (approval-rule.update, rule 12), and so is giving a job step a gated job
 * template. Everyone who may view a workflow sees the positions, the
 * success path and the legend.
 *
 * e2e-operator (operator role, in the team of e2e-template's project) edits
 * e2e-edit-operator-workflow, two plain job steps, and leaves it as seeded
 * (commands/E2eWorkflowUxSeeder.php). e2e-viewer (viewer role, in the team
 * of e2e-beta-proj) reads e2e-beta-approval-wf, an approval step and a job
 * step (commands/E2eWorkflowScopingSeeder.php). Admins:
 * workflow-templates/edit-step.spec.ts and step-position.spec.ts.
 */

/** e2e-beta-approval-wf, which e2e-viewer's team may see. */
const BETA_APPROVAL_WORKFLOW = 'e2e-beta-approval-wf';
const BETA_APPROVE = 'e2e-beta-approval-wf-approve';
const BETA_JOB = 'e2e-beta-approval-wf-job';

/** The step actions on the open page. */
function stepActions(page: Page) {
  return page.locator('#page-content [data-action]');
}

test.describe('Workflow step editing RBAC', () => {
  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });

  test('operator edits, moves and inserts the steps of a workflow without gated steps', async ({ page }) => {
    const [first, second] = [EDIT_OPERATOR_FIRST, EDIT_OPERATOR_SECOND];
    const renamed = 'e2e-edit-operator-renamed';
    const inserted = 'e2e-edit-operator-inserted';
    await openWorkflowTemplate(page, EDIT_OPERATOR_WORKFLOW);
    for (const action of ['edit', 'insert-below', 'move-up', 'move-down', 'move-to', 'remove']) {
      await expect(templateStepRow(page, first).locator(`[data-action="${action}"]`), action).toHaveCount(1);
    }

    // Edit: the step keeps its ID.
    const firstId = await templateStepId(page, first);
    expect(await editStepThroughForm(page, first, { name: renamed })).toBe(stepSaved(renamed, 'name'));
    await expect(templateStepRow(page, renamed)).toHaveAttribute('data-step-id', firstId);
    expect(await editStepThroughForm(page, renamed, { name: first })).toBe(stepSaved(first, 'name'));

    // ▲ moves the second step to the start, ▲ on the first moves it back.
    for (const [moved, other] of [[second, first], [first, second]]) {
      await Promise.all([
        page.waitForURL(/#step-\d+$/),
        templateStepRow(page, moved).locator('[data-action="move-up"]').click(),
      ]);
      await expect(page.locator('#page-content > .alert-success.alert-dismissible')).toContainText(stepMoved(moved, 1, 2));
      await expectWarningFlash(page, startChanged(moved, other));
      await expect(templateStepNames(page)).toHaveText([moved, other]);
    }

    // + Insert below the first step: the new step lands right below it.
    await templateStepRow(page, first).locator('a[data-action="insert-below"]').click();
    await expect(page.locator(POSITION_SELECT)).toHaveValue(`after:${firstId}`);
    await removingSteps(page, [inserted], async () => {
      await addStepThroughForm(page, { name: inserted, type: 'job', target: JOB_TEMPLATE });
      await expect(page.locator('#page-content > .alert-success.alert-dismissible')).toContainText(stepAdded(inserted, 2, 3, first));
      await expect(templateStepNames(page)).toHaveText([first, inserted, second]);
    });
    await expect(templateStepNames(page)).toHaveText([first, second]);
  });

  test('operator sees the steps of a workflow with an approval step or a gated job step, without step actions', async ({ page }) => {
    for (const [workflow, reason] of [
      [ROUTES_WORKFLOW, APPROVAL_WORKFLOW_STEPS_REFUSED],
      [APPROVAL_WORKFLOW, APPROVAL_WORKFLOW_STEPS_REFUSED],
      [GATED_JOB_WORKFLOW, GATED_JOB_WORKFLOW_STEPS_REFUSED],
    ]) {
      await openWorkflowTemplate(page, workflow);
      await expect(page.locator(STEPS_LOCKED_NOTICE), workflow).toHaveText(stepsLockedNotice(reason));
      await expect(stepActions(page), workflow).toHaveCount(0);
      await expect(page.locator('#add-step-form'), workflow).toHaveCount(0);
      await expect(page.locator('#workflow-steps-table thead th'), workflow).toHaveText(STEPS_COLUMNS);
      await expect(page.locator('#wf-steps-legend'), workflow).toHaveText(LEGEND);
      await expect(templateStepPositions(page).first(), workflow).toHaveText('1');
    }
  });

  test('operator gets 403 with the reason on a forged edit or move of a step of a gated workflow', async ({ page }) => {
    for (const [workflow, step, reason] of [
      [GATED_JOB_WORKFLOW, GATED_JOB_AFTER, GATED_JOB_WORKFLOW_STEPS_REFUSED],
      [APPROVAL_WORKFLOW, 'e2e-step-job-after-approval', APPROVAL_WORKFLOW_STEPS_REFUSED],
    ]) {
      const id = await openWorkflowTemplate(page, workflow);
      const before = await templateStepNames(page).allTextContents();
      const stepId = await templateStepId(page, step);
      const edit = `/workflow-template/edit-step?id=${id}&step=${stepId}`;

      const response = await page.goto(edit);
      expect(response?.status(), `the edit page of ${step}`).toBe(403);
      await expect(page.locator('body')).toContainText(reason);

      // Real form posts with a valid CSRF token: only the server checks may refuse them.
      const changes: [string, Record<string, string>, RegExp][] = [
        [edit, { 'WorkflowStep[name]': 'e2e-operator-renamed', position: 'keep' }, /\/workflow-template\/edit-step/],
        [`/workflow-template/move-step?id=${id}`, { step_id: stepId, position: 'start' }, /\/workflow-template\/move-step/],
      ];
      for (const [action, fields, landsOn] of changes) {
        await page.goto('/workflow-template/index');
        await forgePost(page, action, fields, landsOn);
        await expectForbidden(page);
        await expect(page.locator('body')).toContainText(reason);
      }

      await openWorkflowTemplate(page, workflow);
      expect(await templateStepNames(page).allTextContents()).toEqual(before);
    }
  });

  test('operator gets 403 on a forged edit that gives a job step a job template with an approval rule', async ({ page }) => {
    // The operator may see the template, so its ID is no secret: the job list filters by it.
    const gatedId = await templateId(page, GATED_JOB_TEMPLATE);
    const id = await openWorkflowTemplate(page, EDIT_OPERATOR_WORKFLOW);
    const stepId = await templateStepId(page, EDIT_OPERATOR_FIRST);

    // A real form post with a valid CSRF token: only the server checks may stop it.
    await page.goto('/workflow-template/index');
    await forgePost(page, `/workflow-template/edit-step?id=${id}&step=${stepId}`, {
      'WorkflowStep[name]': EDIT_OPERATOR_FIRST,
      'WorkflowStep[job_template_id]': gatedId,
      position: 'keep',
    }, /\/workflow-template\/edit-step/);
    await expectForbidden(page);
    await expect(page.locator('body')).toContainText(GATED_JOB_STEP_ADD_REFUSED);

    await openWorkflowTemplate(page, EDIT_OPERATOR_WORKFLOW);
    const row = templateStepRow(page, EDIT_OPERATOR_FIRST);
    await expect(row.locator('td[data-target-cell]')).toHaveText(JOB_TEMPLATE);
    await expect(row.locator('[data-gated-badge]')).toHaveCount(0);
  });

  test('viewer sees the positions, the success path and the legend, but no step actions', async ({ page }) => {
    await openWorkflowTemplate(page, BETA_APPROVAL_WORKFLOW);
    await expect(templateStepNames(page)).toHaveText([BETA_APPROVE, BETA_JOB]);
    await expect(templateStepPositions(page)).toHaveText(['1', '2']);
    await expect(templateStepRow(page, BETA_APPROVE).locator('[data-start-badge]')).toHaveText(START_BADGE);
    await expect(page.locator('[data-success-path]')).toHaveText(
      successPath(`${stepLabel(1, BETA_APPROVE)}${APPROVAL_STEP_SUFFIX}`, stepLabel(2, BETA_JOB), ROUTE_END_SUCCEEDS),
    );
    await expect(page.locator('#wf-steps-legend')).toHaveText(LEGEND);
    await expect(page.locator('#workflow-steps-table thead th')).toHaveText(STEPS_COLUMNS);
    await expect(stepActions(page)).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toHaveCount(0);
  });

  test('viewer gets 403 on the edit page of a step and on a forged move', async ({ page }) => {
    const id = await openWorkflowTemplate(page, BETA_APPROVAL_WORKFLOW);
    const stepId = await templateStepId(page, BETA_JOB);

    const response = await page.goto(`/workflow-template/edit-step?id=${id}&step=${stepId}`);
    expect(response?.status()).toBe(403);
    await expectForbidden(page);

    // A real form post with a valid CSRF token: only the permission check may stop it.
    await page.goto('/workflow-template/index');
    await forgePost(page, `/workflow-template/move-step?id=${id}`, { step_id: stepId, position: 'start' }, /\/workflow-template\/move-step/);
    await expectForbidden(page);

    await openWorkflowTemplate(page, BETA_APPROVAL_WORKFLOW);
    await expect(templateStepNames(page)).toHaveText([BETA_APPROVE, BETA_JOB]);
  });
});

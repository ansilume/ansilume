import { test, expect, Page } from '@playwright/test';
import { expectFlash, postWithPageCsrf, submitForm } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  GATE_RULE,
  JOB_TEMPLATE,
  LEGEND,
  MOVE_MENU_LIMIT,
  MOVE_TO_END,
  MOVE_TO_START,
  NEXT_OPTION_NONE_FAILS,
  NEXT_OPTION_NONE_SUCCEEDS,
  POSITION_FIELD_ID,
  POSITION_PREVIEW,
  POSITION_SELECT,
  POSITION_START,
  POSITION_WARNING,
  ROUTES_STEPS,
  ROUTES_WORKFLOW,
  ROUTE_END_SUCCEEDS,
  START_BADGE,
  STEP_NOT_ADDED,
  END_FAILS_OPTION,
  THIS_APPROVAL_OFF_PATH,
  UNREACHABLE_TITLE,
  WARNING_FLASH,
  addStepThroughForm,
  afterStepMissing,
  approvalOffSuccessPath,
  editStepThroughForm,
  expectWarningFlash,
  jobBeforeApproval,
  moveStepTo,
  nextFailureOption,
  nextOption,
  positionAfter,
  positionEnd,
  previewSkippedTo,
  removeTemplateStep,
  runStepRow,
  startChanged,
  stepAdded,
  stepLabel,
  stepMoved,
  stepSaved,
  stepStays,
  successPath,
  templateStepId,
  templateStepNames,
  templateStepPositions,
  templateStepRow,
  thisWillPrecedeApproval,
  typeBadge,
  willPrecedeApproval,
  willSkipApproval,
  willStart,
  willStartThis,
  withNewWorkflow,
} from '../../lib/workflows';

/**
 * Where a step goes: the Position of the add-step form (at the end by
 * default, at the start, or after a step), + Insert below, ▲ / ▼, and the
 * Move to… menu. The table shows positions (1, 2, 3…), never the internal
 * step order, and runs start with step 1.
 *
 * Regression (2.10.0, fixed in 2.10.1 with an empty Step Order, now the
 * Position "At the end"): every add and move renumbers the steps 10, 20,
 * 30…, but the form pre-filled Step Order with the number of steps (0, 1,
 * 2…). From the second step on, a step added with that value landed first
 * and became the start step: Build, Test and Deploy, added in turn, ran as
 * Deploy, Test, Build, and a job step added after an approval step ran
 * before it, without approval.
 *
 * e2e-admin, who may add approval steps, adds steps to workflows the tests
 * create through the form and delete again (which cancels their runs). The
 * Position choices are read on e2e-routes-workflow, which stays as seeded
 * (commands/E2eWorkflowUxSeeder.php). Operators and viewers:
 * workflow-templates/step-editing-rbac.spec.ts and rbac.spec.ts.
 */

/** The success flash of the open page, whole. */
function successFlash(page: Page) {
  return page.locator('#page-content > .alert-success.alert-dismissible');
}

/** Adds the pause steps `names` in turn, each at the end. */
async function addPauseSteps(page: Page, names: string[]): Promise<void> {
  for (const name of names) {
    await addStepThroughForm(page, { name, type: 'pause' });
  }
}

test.describe('Positions of workflow steps (admin)', () => {
  test('regression: steps added with the default Position run in the order they were added', async ({ page }) => {
    const [build, check, deploy] = ['e2e-position-build', 'e2e-position-test', 'e2e-position-deploy'];
    await withNewWorkflow(page, 'e2e-position-order-workflow', async () => {
      // The first step has no Position to choose: it starts the runs.
      await expect(page.locator(POSITION_SELECT)).toHaveCount(0);
      await expect(page.locator('#ws-position-first-step')).toHaveText('The first step: runs will start with it.');
      await addStepThroughForm(page, { name: build, type: 'job', target: JOB_TEMPLATE });
      await expect(successFlash(page)).toHaveText(stepAdded(build, 1, 1));

      // The Position is left as the form shows it: at the end.
      await expect(page.locator(POSITION_SELECT)).toHaveValue('end');
      await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveText(positionEnd(stepLabel(1, build)));
      await addStepThroughForm(page, { name: check, type: 'job', target: JOB_TEMPLATE });
      // A new job step ends the workflow when it fails, so only the build
      // step's empty On Success leads elsewhere now.
      await expect(successFlash(page)).toHaveText(
        `${stepAdded(check, 2, 2, build)} "${build}" now continues with "${check}" (was the end of the run).`,
      );
      await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveText(positionEnd(stepLabel(2, check)));
      await addStepThroughForm(page, { name: deploy, type: 'job', target: JOB_TEMPLATE });
      await expect(successFlash(page)).toContainText(stepAdded(deploy, 3, 3, check));

      await expect(templateStepNames(page)).toHaveText([build, check, deploy]);
      await expect(templateStepPositions(page)).toHaveText(['1', '2', '3']);
      // The step added first starts the runs.
      await expect(page.locator('[data-start-badge]')).toHaveCount(1);
      await expect(templateStepRow(page, build).locator('[data-start-badge]')).toHaveText(START_BADGE);
      await expect(page.locator('[data-success-path]')).toHaveText(
        successPath(stepLabel(1, build), stepLabel(2, check), stepLabel(3, deploy), ROUTE_END_SUCCEEDS),
      );
      await expect(page.locator('#wf-steps-legend')).toHaveText(LEGEND);
    });
  });

  test('regression: a job step added with the default Position lands after the approval step', async ({ page }) => {
    const workflow = 'e2e-position-approval-workflow';
    const approve = 'e2e-position-approve';
    const jobs = ['e2e-position-migrate', 'e2e-position-release'];
    await withNewWorkflow(page, workflow, async () => {
      await addStepThroughForm(page, { name: approve, type: 'approval', target: GATE_RULE });
      for (const [index, name] of jobs.entries()) {
        // The Position is left as the form shows it.
        await addStepThroughForm(page, { name, type: 'job', target: JOB_TEMPLATE });
        await expect(successFlash(page)).toContainText(stepAdded(name, index + 2, index + 2, index === 0 ? approve : jobs[0]));
        // No job step got ahead of the approval step: no warning.
        await expect(page.locator('#page-content > .alert-warning.alert-dismissible')).toHaveCount(0);
        await expect(templateStepNames(page)).toHaveText([approve, ...jobs.slice(0, index + 1)]);
      }
      await expect(templateStepPositions(page)).toHaveText(['1', '2', '3']);
      await expect(typeBadge(templateStepRow(page, approve))).toHaveText('Approval');

      // A run starts with the approval step and waits there: no job step
      // has started. Deleting the workflow afterwards cancels the run.
      page.once('dialog', (dialog) => dialog.accept());
      await Promise.all([
        page.waitForURL(/\/workflow-job\/view\?id=\d+$/),
        page.locator('#page-content form[action*="/workflow-template/launch"] button[type="submit"]').click(),
      ]);
      await expectFlash(page, 'success', `Workflow "${workflow}" launched.`);
      await expect(page.locator('#wj-steps-table tbody tr[data-wjs-id]')).toHaveCount(1);
      await expect(runStepRow(page, approve).locator('[data-wjs-type-cell]')).toHaveText('Approval');
    });
  });

  test('"At the start" warns before the add, and the flashes say that runs start with the new step', async ({ page }) => {
    const [first, added] = ['e2e-position-first', 'e2e-position-new-start'];
    await withNewWorkflow(page, 'e2e-position-start-workflow', async () => {
      await addStepThroughForm(page, { name: first, type: 'pause' });

      await page.locator('#workflowstep-name').fill(added);
      await page.locator('#ws-step-type').selectOption('pause');
      const warning = page.locator(POSITION_WARNING);
      await expect(warning).toBeHidden();
      await page.locator(POSITION_SELECT).selectOption({ label: POSITION_START });
      await expect(page.locator(POSITION_PREVIEW)).toHaveText(`Runs first, before "${first}".`);
      await expect(warning).toBeVisible();
      await expect(warning).toHaveText(willStartThis(first));
      await submitForm(page, 'Add Step');

      await expect(successFlash(page)).toHaveText(stepAdded(added, 1, 2));
      await expectWarningFlash(page, startChanged(added, first));
      await expect(templateStepNames(page)).toHaveText([added, first]);
      await expect(templateStepRow(page, added).locator('[data-start-badge]')).toHaveText(START_BADGE);
      await expect(templateStepRow(page, first).locator('[data-start-badge]')).toHaveCount(0);
    });
  });

  // W2-T1 checked the page script by hand only: the Position select drives
  // the preview, the warnings (the job-step warning only for a job step) and
  // the "Next in the list" choices, and the type keeps them so. The
  // approval-step warning has a test of its own below.
  test('the Position drives the preview, the warnings and the "Next in the list" choices', async ({ page }) => {
    await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const buildId = await templateStepId(page, ROUTES_STEPS.build);
    const type = page.locator('#ws-step-type');
    const preview = page.locator(POSITION_PREVIEW);
    const warning = page.locator(POSITION_WARNING);
    const nextSuccess = page.locator('#workflowstep-on_success_step_id option[value=""]');
    const nextFailure = page.locator('#workflowstep-on_failure_step_id option[value=""]');

    // At the end, the default: after the last step, no warning.
    await expect(page.locator(POSITION_SELECT)).toHaveValue('end');
    await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveText(positionEnd(stepLabel(7, ROUTES_STEPS.spare)));
    await expect(preview).toHaveText(`Runs last, after "${ROUTES_STEPS.spare}".`);
    await expect(warning).toBeHidden();
    await expect(nextSuccess).toHaveText(NEXT_OPTION_NONE_SUCCEEDS);
    await expect(nextFailure).toHaveText(NEXT_OPTION_NONE_FAILS);

    // After 1. Build: between Build and the approval step, which a job step
    // there would not wait for.
    await page.locator(POSITION_SELECT).selectOption({ label: positionAfter(stepLabel(1, ROUTES_STEPS.build)) });
    await expect(page.locator(POSITION_SELECT)).toHaveValue(`after:${buildId}`);
    await expect(preview).toHaveText(`Runs after "${ROUTES_STEPS.build}", before "${ROUTES_STEPS.approve}".`);
    await expect(warning).toHaveText(thisWillPrecedeApproval(ROUTES_STEPS.approve));
    await expect(nextSuccess).toHaveText(nextOption(ROUTES_STEPS.approve));
    await expect(nextFailure).toHaveText(nextFailureOption(ROUTES_STEPS.approve));

    // The job-step warning is for job steps only. An approval step after
    // Build is on the success path, so it gets no warning either.
    await type.selectOption('pause');
    await expect(warning).toBeHidden();
    await type.selectOption('approval');
    await expect(warning).toBeHidden();
    await expect(nextFailure).toHaveText(END_FAILS_OPTION);
    await type.selectOption('job');
    await expect(warning).toHaveText(thisWillPrecedeApproval(ROUTES_STEPS.approve));
    await expect(nextFailure).toHaveText(nextFailureOption(ROUTES_STEPS.approve));

    // At the start: another start step, and before the approval step.
    await page.locator(POSITION_SELECT).selectOption({ label: POSITION_START });
    await expect(preview).toHaveText(`Runs first, before "${ROUTES_STEPS.build}".`);
    await expect(warning).toHaveText(`${willStartThis(ROUTES_STEPS.build)} ${thisWillPrecedeApproval(ROUTES_STEPS.approve)}`);
    await expect(nextSuccess).toHaveText(nextOption(ROUTES_STEPS.build));
    await expect(nextFailure).toHaveText(nextFailureOption(ROUTES_STEPS.build));
    await type.selectOption('approval');
    await expect(warning).toHaveText(willStartThis(ROUTES_STEPS.build));

    // Back at the end, nothing to warn about for a job step. (An approval
    // step there would be off the success path: the cleanup step ends the run.)
    await type.selectOption('job');
    await page.locator(POSITION_SELECT).selectOption('end');
    await expect(warning).toBeHidden();
    await expect(preview).toHaveText(`Runs last, after "${ROUTES_STEPS.spare}".`);
    await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveAttribute('data-warn-approval', THIS_APPROVAL_OFF_PATH);
  });

  // regression: + Insert below left the name unfocused. Its link opens the
  // page at the form (#add-step), and a browser skips autofocus on a page
  // opened at a fragment, so the step form script must focus the field.
  test('+ Insert below opens the form with the Position after the step and the name focused', async ({ page }) => {
    const id = await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const deployId = await templateStepId(page, ROUTES_STEPS.deploy);
    const insert = templateStepRow(page, ROUTES_STEPS.deploy).locator('a[data-action="insert-below"]');
    await expect(insert).toHaveAttribute('aria-label', `Insert a step below step 3 "${ROUTES_STEPS.deploy}"`);
    await insert.click();

    await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=${id}&after=${deployId}#add-step$`));
    await expect(page.locator(POSITION_SELECT)).toHaveValue(`after:${deployId}`);
    await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveText(positionAfter(stepLabel(3, ROUTES_STEPS.deploy)));
    await expect(page.locator(POSITION_PREVIEW)).toHaveText(`Runs after "${ROUTES_STEPS.deploy}", before "${ROUTES_STEPS.verify}".`);
    await expect(page.locator('#workflowstep-name')).toBeFocused();

    // Below the last step is the end.
    await templateStepRow(page, ROUTES_STEPS.spare).locator('a[data-action="insert-below"]').click();
    await expect(page).toHaveURL(/&after=\d+#add-step$/);
    await expect(page.locator(POSITION_SELECT)).toHaveValue('end');
    await expect(page.locator('#workflowstep-name')).toBeFocused();
  });

  test('a step added with + Insert below lands right below the step', async ({ page }) => {
    const [first, last, inserted] = ['e2e-insert-first', 'e2e-insert-last', 'e2e-insert-below'];
    await withNewWorkflow(page, 'e2e-insert-below-workflow', async () => {
      await addPauseSteps(page, [first, last]);
      await templateStepRow(page, first).locator('a[data-action="insert-below"]').click();
      await expect(page.locator('#workflowstep-name')).toBeFocused();
      await page.keyboard.type(inserted);
      await page.locator('#ws-step-type').selectOption('pause');
      await submitForm(page, 'Add Step');

      await expect(successFlash(page)).toContainText(stepAdded(inserted, 2, 3, first));
      await expect(templateStepNames(page)).toHaveText([first, inserted, last]);
    });
  });

  // Regression (W2-T2): a move redirected without the step's anchor, so the
  // page jumped to the top. It shows the moved step now, highlighted.
  test('▲ and ▼ move a step by one place, and the page shows the moved step', async ({ page }) => {
    const [a, b, c] = ['e2e-arrow-a', 'e2e-arrow-b', 'e2e-arrow-c'];
    await withNewWorkflow(page, 'e2e-arrow-workflow', async (id) => {
      await addPauseSteps(page, [a, b, c]);
      const rows = page.locator('#workflow-steps-table tbody tr');
      // Nothing above the first step, nothing below the last.
      await expect(rows.first().locator('[data-action="move-up"]')).toBeDisabled();
      await expect(rows.first().locator('[data-action="move-up"]')).toHaveClass(/opacity-25/);
      await expect(rows.last().locator('[data-action="move-down"]')).toBeDisabled();
      await expect(rows.last().locator('[data-action="move-down"]')).toHaveClass(/opacity-25/);

      const bId = await templateStepId(page, b);
      const up = templateStepRow(page, b).locator('[data-action="move-up"]');
      await expect(up).toHaveAttribute('aria-label', `Move step 2 "${b}" up`);
      await Promise.all([page.waitForURL(new RegExp(`/workflow-template/view\\?id=${id}#step-${bId}$`)), up.click()]);
      await expect(successFlash(page)).toHaveText(
        `${stepMoved(b, 1, 3)} "${b}" now continues with "${a}" (was "${c}"). "${a}" now continues with "${c}" (was "${b}").`,
      );
      await expectWarningFlash(page, startChanged(b, a));
      await expect(templateStepNames(page)).toHaveText([b, a, c]);
      // The moved step is the page's target, and highlighted.
      await expect(page.locator('#workflow-steps-table tbody tr:target')).toHaveAttribute('id', `step-${bId}`);
      const highlight = (name: string) => templateStepRow(page, name).locator('td').first()
        .evaluate((cell) => getComputedStyle(cell).getPropertyValue('--bs-table-bg-state').trim());
      expect(await highlight(b)).not.toBe('');
      expect(await highlight(a)).toBe('');

      const aId = await templateStepId(page, a);
      const down = templateStepRow(page, a).locator('[data-action="move-down"]');
      await expect(down).toHaveAttribute('aria-label', `Move step 2 "${a}" down`);
      await Promise.all([page.waitForURL(new RegExp(`/workflow-template/view\\?id=${id}#step-${aId}$`)), down.click()]);
      await expect(successFlash(page)).toContainText(stepMoved(a, 3, 3, c));
      await expect(page.locator('#page-content > .alert-warning.alert-dismissible')).toHaveCount(0);
      await expect(templateStepNames(page)).toHaveText([b, c, a]);
      await expect(templateStepPositions(page)).toHaveText(['1', '2', '3']);
      await expect(page.locator('#workflow-steps-table tbody tr:target')).toHaveAttribute('id', `step-${aId}`);

      // ▲ on the first step is disabled; a forged one moves nothing and says so.
      await page.goto('/workflow-template/index');
      await forgePost(page, `/workflow-template/move-step?id=${id}`, { step_id: bId, direction: 'up' }, /\/workflow-template\/view/);
      await expect(page).toHaveURL(new RegExp(`#step-${bId}$`));
      await expectFlash(page, 'info', stepStays(b, 1));
      await expect(successFlash(page)).toHaveCount(0);
      await expect(templateStepNames(page)).toHaveText([b, c, a]);
    });
  });

  test('Move to… places a step anywhere in one click, and its hints say what the move changes', async ({ page }) => {
    const [approve, deploy, verify] = ['e2e-move-approve', 'e2e-move-deploy', 'e2e-move-verify'];
    await withNewWorkflow(page, 'e2e-move-to-workflow', async () => {
      await addStepThroughForm(page, { name: approve, type: 'approval', target: GATE_RULE });
      await addStepThroughForm(page, { name: deploy, type: 'job', target: JOB_TEMPLATE });
      await addStepThroughForm(page, { name: verify, type: 'job', target: JOB_TEMPLATE });

      // Targets that would leave the step where it is are left out: after
      // the step above it, and after the last step, which is the end.
      const menu = templateStepRow(page, deploy).locator('details[data-action="move-to"]');
      await expect(menu.locator('summary')).toHaveAttribute('aria-label', `Move step 2 "${deploy}" to another position`);
      await menu.locator('summary').click();
      await expect(menu.locator('button[name="position"]')).toHaveText([MOVE_TO_START, MOVE_TO_END]);
      const toStart = menu.locator('.wf-move-target').filter({ hasText: MOVE_TO_START });
      await expect(toStart.locator('[data-move-hint]')).toHaveText([willStart(deploy, approve), willPrecedeApproval(deploy, approve)]);
      await expect(menu.locator('.wf-move-target').filter({ hasText: MOVE_TO_END }).locator('[data-move-hint]')).toHaveCount(0);

      await moveStepTo(page, deploy, MOVE_TO_START);
      await expect(successFlash(page)).toContainText(stepMoved(deploy, 1, 3));
      await expectWarningFlash(page, startChanged(deploy, approve));
      await expectWarningFlash(page, jobBeforeApproval(deploy, approve));
      await expect(templateStepNames(page)).toHaveText([deploy, approve, verify]);

      // And back: the approval step to the start again.
      const approveMenu = templateStepRow(page, approve).locator('details[data-action="move-to"]');
      await approveMenu.locator('summary').click();
      await expect(approveMenu.locator('button[name="position"]')).toHaveText([MOVE_TO_START, MOVE_TO_END]);
      await expect(approveMenu.locator('.wf-move-target').filter({ hasText: MOVE_TO_END }).locator('[data-move-hint]'))
        .toHaveText([willPrecedeApproval(verify, approve)]);
      await moveStepTo(page, approve, MOVE_TO_START);
      await expect(successFlash(page)).toContainText(stepMoved(approve, 1, 3));
      await expectWarningFlash(page, startChanged(approve, deploy));
      await expect(page.locator('#page-content > .alert-warning.alert-dismissible')).toHaveCount(1);
      await expect(templateStepNames(page)).toHaveText([approve, deploy, verify]);
    });
  });

  // Regression (wave 5, R12-1): the preview, the warnings and the Move to…
  // hints went by position only. An approval step inserted below a step whose
  // On Success named the next step read 'Runs after "Build", before
  // "Deploy".' and nothing warned, while every run that succeeds goes from
  // Build to Deploy and never waits for the approval.
  test('regression: an approval step inserted below a step whose On Success leads past it is flagged', async ({ page }) => {
    const [build, deploy, approve] = ['e2e-skip-build', 'e2e-skip-deploy', 'e2e-skip-approve'];
    await withNewWorkflow(page, 'e2e-skip-approval-workflow', async () => {
      await addStepThroughForm(page, { name: build, type: 'job', target: JOB_TEMPLATE });
      await addStepThroughForm(page, { name: deploy, type: 'job', target: JOB_TEMPLATE });
      // An explicit On Success, as workflows built before 2.11.0 often have.
      expect(await editStepThroughForm(page, build, { onSuccess: stepLabel(2, deploy) })).toBe(stepSaved(build, 'On Success'));

      await templateStepRow(page, build).locator('a[data-action="insert-below"]').click();
      await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveText(positionAfter(stepLabel(1, build)));
      await expect(page.locator(POSITION_PREVIEW)).toHaveText(previewSkippedTo(build, deploy));
      // Nothing to warn about for a job step there.
      await expect(page.locator(POSITION_WARNING)).toBeHidden();
      await page.locator('#workflowstep-name').fill(approve);
      await page.locator('#ws-step-type').selectOption('approval');
      await expect(page.locator(POSITION_PREVIEW)).toHaveText(previewSkippedTo(build, deploy));
      // The warning an approval step there gets, shown before the add: the
      // page script shows data-warn-approval for an approval step.
      await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveAttribute('data-warn-approval', THIS_APPROVAL_OFF_PATH);
      await expect(page.locator(POSITION_WARNING)).toBeVisible();
      await expect(page.locator(POSITION_WARNING)).toHaveText(THIS_APPROVAL_OFF_PATH);
      await page.locator('#workflowstep-approval_rule_id').selectOption({ label: GATE_RULE });
      await submitForm(page, 'Add Step');

      await expect(successFlash(page)).toHaveText(stepAdded(approve, 2, 3, build));
      await expectWarningFlash(page, approvalOffSuccessPath(approve));
      await expect(page.locator(WARNING_FLASH)).toHaveCount(1);
      // Build's success leads past it, and its failure ends the workflow (the form's default).
      await expect(templateStepRow(page, approve).locator('[data-unreachable-badge]')).toHaveAttribute('title', UNREACHABLE_TITLE);
      await expect(page.locator('[data-success-path]')).toHaveText(successPath(stepLabel(1, build), stepLabel(3, deploy), ROUTE_END_SUCCEEDS));

      // At the end, runs that succeed pass it: no warning.
      await moveStepTo(page, approve, MOVE_TO_END);
      await expect(successFlash(page)).toContainText(stepMoved(approve, 3, 3, deploy));
      await expect(page.locator(WARNING_FLASH)).toHaveCount(0);
      await expect(templateStepRow(page, approve).locator('[data-unreachable-badge]')).toHaveCount(0);

      // Move to… says so before a move back below the build step.
      const menu = templateStepRow(page, approve).locator('details[data-action="move-to"]');
      await menu.locator('summary').click();
      const back = menu.locator('.wf-move-target').filter({ hasText: positionAfter(stepLabel(1, build)) });
      await expect(back.locator('[data-move-hint]')).toHaveText([willSkipApproval(approve)]);
    });
  });

  // Regression (wave 5b, R12-1 in the browser): the page script showed the
  // Position's warnings for any type and, for a job step, the job-step
  // warning, but never the warning the server renders for an approval step
  // that no success would lead to (data-warn-approval). Add Step said
  // nothing before such an add; only the warning flash after it did.
  // e2e-routes-workflow stays as seeded: nothing is added.
  test('regression: Add Step warns before an approval step is placed where no success leads, for an approval step only', async ({ page }) => {
    await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const type = page.locator('#ws-step-type');
    const position = page.locator(POSITION_SELECT);
    const warning = page.locator(POSITION_WARNING);

    // At the end, the default: no success leads there, as the cleanup step
    // ends the run and nothing leads to the spare step.
    await expect(position).toHaveValue('end');
    await expect(warning).toBeHidden();
    await type.selectOption('approval');
    await expect(warning).toBeVisible();
    await expect(warning).toHaveText(THIS_APPROVAL_OFF_PATH);

    // Not for a job step, nor for a pause step.
    await type.selectOption('job');
    await expect(warning).toBeHidden();
    await type.selectOption('pause');
    await expect(warning).toBeHidden();

    // The Position decides it with the type: Build's success leads to a step
    // placed right after it.
    await type.selectOption('approval');
    await expect(warning).toHaveText(THIS_APPROVAL_OFF_PATH);
    await position.selectOption({ label: positionAfter(stepLabel(1, ROUTES_STEPS.build)) });
    await expect(warning).toBeHidden();
    await position.selectOption('end');
    await expect(warning).toBeVisible();
    await expect(warning).toHaveText(THIS_APPROVAL_OFF_PATH);
  });

  // Regression (wave 5, W5-OPS-2): every row's Move to… listed every other
  // place with its hints, so the page grew with the square of the steps
  // (1.2 MB for 50). Above MOVE_MENU_LIMIT steps it links to the step's edit
  // page instead, whose Position field lists every place with its warnings.
  test('regression: above the limit, Move to… opens the page of the step at its Position', async ({ page }) => {
    const names = Array.from({ length: MOVE_MENU_LIMIT + 1 }, (_, index) => `e2e-many-${String(index + 1).padStart(2, '0')}`);
    await withNewWorkflow(page, 'e2e-many-steps-workflow', async (id) => {
      for (const name of names) {
        const added = await postWithPageCsrf(page, `/workflow-template/add-step?id=${id}`, {
          'WorkflowStep[name]': name,
          'WorkflowStep[step_type]': 'pause',
        });
        expect(added.status(), `add ${name}`).toBe(302);
      }
      await page.goto(`/workflow-template/view?id=${id}`);
      await expect(templateStepNames(page)).toHaveText(names);
      await expect(page.locator('details[data-action="move-to"]')).toHaveCount(0);
      await expect(page.locator('a[data-action="move-to"]')).toHaveCount(names.length);

      const last = names[names.length - 1];
      const lastId = await templateStepId(page, last);
      const link = templateStepRow(page, last).locator('a[data-action="move-to"]');
      await expect(link).toHaveAttribute('href', `/workflow-template/edit-step?id=${id}&step=${lastId}#${POSITION_FIELD_ID}`);
      await expect(link).toHaveAttribute('aria-label', `Move step ${names.length} "${last}" to another position`);
      await expect(templateStepRow(page, last).locator('[data-action="move-up"]')).toBeEnabled();
      await link.click();
      await expect(page).toHaveURL(new RegExp(`/workflow-template/edit-step\\?id=${id}&step=${lastId}#${POSITION_FIELD_ID}$`));
      await page.locator(POSITION_SELECT).selectOption({ label: POSITION_START });
      await expect(page.locator(POSITION_WARNING)).toHaveText(willStartThis(names[0]));
      await submitForm(page, 'Save Step');
      await expect(successFlash(page)).toContainText(stepMoved(last, 1, names.length));
      await expectWarningFlash(page, startChanged(last, names[0]));
      await expect(templateStepNames(page).first()).toHaveText(last);

      // At the limit, each row has its menu again.
      await removeTemplateStep(page, names[0]);
      await expect(page.locator('details[data-action="move-to"]')).toHaveCount(MOVE_MENU_LIMIT);
      await expect(page.locator('a[data-action="move-to"]')).toHaveCount(0);
    });
  });

  test('a Position after a step that was removed meanwhile is refused, and nothing is added', async ({ page }) => {
    const [first, second, added] = ['e2e-stale-first', 'e2e-stale-second', 'e2e-stale-added'];
    await withNewWorkflow(page, 'e2e-stale-position-workflow', async (id) => {
      await addPauseSteps(page, [first, second]);
      const firstId = await templateStepId(page, first);
      await page.locator('#workflowstep-name').fill(added);
      await page.locator('#ws-step-type').selectOption('pause');
      await page.locator(POSITION_SELECT).selectOption({ label: positionAfter(stepLabel(1, first)) });

      // Meanwhile the step is removed in another tab.
      const other = await page.context().newPage();
      try {
        await other.goto(`/workflow-template/view?id=${id}`);
        await removeTemplateStep(other, first);
      } finally {
        await other.close();
      }

      await submitForm(page, 'Add Step');
      await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=${id}#add-step$`));
      await expectFlash(page, 'danger', `${STEP_NOT_ADDED}${afterStepMissing(firstId)}`);
      await expect(templateStepNames(page)).toHaveText([second]);
    });
  });

  // A Step Order posted by hand is ignored: only Position places a step.
  // Regression (2.10.1): a Step Order larger than the column holds passed
  // every check, and the database refused it with an error page (500).
  test('a Step Order posted by hand is ignored, also one too large for the database', async ({ page }) => {
    const [first, posted, large] = ['e2e-order-first', 'e2e-order-posted', 'e2e-order-too-large'];
    await withNewWorkflow(page, 'e2e-posted-order-workflow', async (id) => {
      await addPauseSteps(page, [first]);
      for (const [name, order] of [[posted, '0'], [large, '3000000000']]) {
        // A real form post with a valid CSRF token, without a Position.
        await page.goto('/workflow-template/index');
        await forgePost(page, `/workflow-template/add-step?id=${id}`, {
          'WorkflowStep[name]': name,
          'WorkflowStep[step_type]': 'pause',
          'WorkflowStep[step_order]': order,
        }, /\/workflow-template\/view/);
        await expect(successFlash(page)).toContainText(`Step "${name}" added at position `);
      }
      await expect(templateStepNames(page)).toHaveText([first, posted, large]);
      await expect(templateStepPositions(page)).toHaveText(['1', '2', '3']);
    });
  });
});

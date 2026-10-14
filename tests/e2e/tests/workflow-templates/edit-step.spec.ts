import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  ALWAYS_NONE_OPTION,
  EDIT_BUILD,
  EDIT_DEPLOY,
  EDIT_STEPS_WORKFLOW,
  EDIT_TEST,
  END_FAILS_OPTION,
  GATE_RULE,
  JOB_TEMPLATE,
  LEGACY_ALWAYS_NOTE,
  LEGACY_STEPS,
  LEGACY_WORKFLOW,
  NEXT_OPTION_NONE_FAILS,
  POSITION_SELECT,
  POSITION_START,
  POSITION_WARNING,
  ROUTED_BY_ALWAYS_WINS,
  ROUTED_BY_NONE,
  ROUTES_STEPS,
  ROUTE_ALSO_AFTER_FAILURE,
  ROUTES_WORKFLOW,
  ROUTE_END_FAILS,
  ROUTE_END_SUCCEEDS,
  ROUTE_UNUSED,
  STEP_NOT_SAVED,
  SUCCESS_LABEL,
  SUCCESS_LABEL_PAUSE,
  TEMPLATE_GONE_HINT,
  TYPE_LOCKED,
  addStepThroughForm,
  editStepThroughForm,
  expectWarningFlash,
  fillStepForm,
  nextFailureOption,
  nextOption,
  nowEnds,
  positionAfter,
  positionEnd,
  positionKeep,
  removeTemplateStep,
  removingSteps,
  routeCell,
  routeLoop,
  routeNext,
  routeTo,
  startChanged,
  stepLabel,
  stepReference,
  stepRemoved,
  stepRoutedTo,
  stepSaved,
  stepSavedMoved,
  stepUnchanged,
  templateStepId,
  templateStepNames,
  templateStepRow,
  willStartThis,
  withNewWorkflow,
} from '../../lib/workflows';

/**
 * Edit Step: a step's name, target, branches and position change in place,
 * on the page /workflow-template/edit-step. The step keeps its ID, so the
 * branches that lead to it and its run history stay; its type is fixed.
 *
 * Regression: a step could not be edited. A branch could only be changed by
 * removing the step and adding it again, which a step that other steps route
 * to refuses: those had to go first, with their own branches.
 *
 * Fixtures (commands/E2eWorkflowUxSeeder.php): e2e-edit-steps-workflow
 * (e2e-edit-build, whose On Failure leads to e2e-edit-deploy, e2e-edit-test,
 * e2e-edit-deploy), which each test changes and leaves as seeded;
 * e2e-routes-workflow and e2e-routes-legacy-workflow, which the tests only
 * read. As e2e-admin; operators and viewers: step-editing-rbac.spec.ts.
 */

/** The success or info flash of a step change on the open page, whole. */
function changeFlash(page: Page) {
  return page.locator('#page-content > .alert-success.alert-dismissible, #page-content > .alert-info.alert-dismissible');
}

/** Opens the edit page of the step named `name` of the open template page with its Edit button; the step's ID. */
async function openEditPage(page: Page, workflowId: string, name: string): Promise<string> {
  const stepId = await templateStepId(page, name);
  await templateStepRow(page, name).locator('a[data-action="edit"]').click();
  await expect(page).toHaveURL(new RegExp(`/workflow-template/edit-step\\?id=${workflowId}&step=${stepId}$`));
  return stepId;
}

test.describe('Edit workflow steps (admin)', () => {
  test('a step keeps its ID through an edit, and Position moves it', async ({ page }) => {
    const id = await openWorkflowTemplate(page, EDIT_STEPS_WORKFLOW);
    const renamed = 'e2e-edit-test-first';
    const stepId = await openEditPage(page, id, EDIT_TEST);

    await expect(page.locator('#page-content h2')).toHaveText(`Edit ${stepReference(2, EDIT_TEST)}`);
    await expect(page.locator('.breadcrumb-item.active')).toHaveText(stepReference(2, EDIT_TEST));
    await expect(page.locator('#edit-step-form button[type="submit"]')).toHaveText('Save Step');
    await expect(page.locator('#edit-step-cancel')).toHaveAttribute('href', `/workflow-template/view?id=${id}#step-${stepId}`);
    // The type is fixed: a type change would add or drop a gate.
    await expect(page.locator('#ws-step-type')).toBeDisabled();
    await expect(page.locator('#ws-type-hint')).toHaveText(TYPE_LOCKED);
    // The step stays where it is unless another Position is chosen.
    await expect(page.locator(POSITION_SELECT)).toHaveValue('keep');
    await expect(page.locator(`${POSITION_SELECT} option:checked`)).toHaveText(positionKeep(2));
    await expect(page.locator('#workflowstep-name')).toHaveValue(EDIT_TEST);
    await expect(page.locator('#workflowstep-on_always_step_id option[value=""]')).toHaveText(ALWAYS_NONE_OPTION);
    await expect(page.locator('#edit-step-routed-by')).toContainText(ROUTED_BY_NONE);

    await fillStepForm(page, { name: renamed, position: POSITION_START });
    await expect(page.locator(POSITION_WARNING)).toHaveText(willStartThis(EDIT_BUILD));
    await submitForm(page, 'Save Step');

    // The template page shows the step, which kept its ID, at its new place.
    await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=${id}#step-${stepId}$`));
    await expect(changeFlash(page)).toContainText(stepSavedMoved(renamed, ['name'], 1, 3));
    await expectWarningFlash(page, startChanged(renamed, EDIT_BUILD));
    await expect(templateStepNames(page)).toHaveText([renamed, EDIT_BUILD, EDIT_DEPLOY]);
    await expect(templateStepRow(page, renamed)).toHaveAttribute('data-step-id', stepId);
    await expect(templateStepRow(page, renamed).locator('[data-start-badge]')).toHaveCount(1);
    await expect(page.locator('#workflow-steps-table tbody tr:target')).toHaveAttribute('id', `step-${stepId}`);

    // And back, as seeded.
    const restored = await editStepThroughForm(page, renamed, { name: EDIT_TEST, position: positionAfter(stepLabel(2, EDIT_BUILD)) });
    expect(restored).toContain(stepSavedMoved(EDIT_TEST, ['name'], 2, 3, EDIT_BUILD));
    await expectWarningFlash(page, startChanged(EDIT_BUILD, EDIT_TEST));
    await expect(templateStepNames(page)).toHaveText([EDIT_BUILD, EDIT_TEST, EDIT_DEPLOY]);
    await expect(templateStepRow(page, EDIT_TEST)).toHaveAttribute('data-step-id', stepId);
  });

  test('regression: a step that other steps route to is changed in place, without removing it', async ({ page }) => {
    const id = await openWorkflowTemplate(page, EDIT_STEPS_WORKFLOW);
    const renamed = 'e2e-edit-deploy-v2';
    const buildId = await templateStepId(page, EDIT_BUILD);
    const deployId = await templateStepId(page, EDIT_DEPLOY);
    const build = () => templateStepRow(page, EDIT_BUILD);

    // A run follows the live branches: the step the build step's On Failure
    // leads to is not removed.
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForURL(/#workflow-steps$/),
      templateStepRow(page, EDIT_DEPLOY).locator('button[data-action="remove"]').click(),
    ]);
    await expectFlash(page, 'danger', stepRoutedTo(EDIT_DEPLOY, stepReference(1, EDIT_BUILD)));
    await expect(templateStepRow(page, EDIT_DEPLOY)).toHaveCount(1);

    // The step it leads to is edited in place: the branch still leads to it.
    await openEditPage(page, id, EDIT_DEPLOY);
    await expect(page.locator('#edit-step-routed-by li')).toHaveText([stepLabel(1, EDIT_BUILD)]);
    await fillStepForm(page, { name: renamed });
    await submitForm(page, 'Save Step');
    await expect(changeFlash(page)).toHaveText(stepSaved(renamed, 'name'));
    await expect(templateStepRow(page, renamed)).toHaveAttribute('data-step-id', deployId);
    await expect(routeCell(build(), 'failure')).toHaveText(routeTo(3, renamed));

    // The branch is changed in place: the build step keeps its ID.
    expect(await editStepThroughForm(page, EDIT_BUILD, { onFailure: END_FAILS_OPTION })).toBe(stepSaved(EDIT_BUILD, 'On Failure'));
    await expect(build()).toHaveAttribute('data-step-id', buildId);
    await expect(routeCell(build(), 'failure')).toHaveText(ROUTE_END_FAILS);

    // Now nothing leads to it, and it can go.
    await removeTemplateStep(page, renamed);
    await expect(changeFlash(page)).toHaveText(`${stepRemoved(renamed, 3, 3)} ${nowEnds(EDIT_TEST, renamed)}`);
    await expect(templateStepNames(page)).toHaveText([EDIT_BUILD, EDIT_TEST]);

    // Back as seeded: the deploy step at the end, and the build step's On Failure leading to it.
    await addStepThroughForm(page, { name: EDIT_DEPLOY, type: 'job', target: JOB_TEMPLATE, onFailure: NEXT_OPTION_NONE_FAILS });
    await editStepThroughForm(page, EDIT_BUILD, { onFailure: stepLabel(3, EDIT_DEPLOY) });
    await expect(templateStepNames(page)).toHaveText([EDIT_BUILD, EDIT_TEST, EDIT_DEPLOY]);
    await expect(routeCell(build(), 'failure')).toHaveText(routeTo(3, EDIT_DEPLOY));
    await expect(build()).toHaveAttribute('data-step-id', buildId);
    const deploy = templateStepRow(page, EDIT_DEPLOY);
    await expect(routeCell(deploy, 'success')).toHaveText(ROUTE_END_SUCCEEDS);
    await expect(routeCell(deploy, 'failure')).toHaveText(ROUTE_END_FAILS);
  });

  test('a branch leads to a step added later, or back to the step itself', async ({ page }) => {
    await openWorkflowTemplate(page, EDIT_STEPS_WORKFLOW);
    const notify = 'e2e-edit-notify';
    const check = () => templateStepRow(page, EDIT_TEST);
    await removingSteps(page, [notify], async () => {
      await addStepThroughForm(page, { name: notify, type: 'pause' });

      // Edit Step offers every step: later ones, and the step itself (a retry).
      const saved = await editStepThroughForm(page, EDIT_TEST, {
        onSuccess: stepLabel(4, notify),
        onFailure: `${stepLabel(2, EDIT_TEST)} (this step)`,
      });
      expect(saved).toBe(stepSaved(EDIT_TEST, 'On Success', 'On Failure'));
      await expect(routeCell(check(), 'success')).toHaveText(routeTo(4, notify));
      await expect(routeCell(check(), 'success')).toHaveAttribute('data-route-kind', 'step');
      await expect(routeCell(check(), 'failure')).toHaveText(routeLoop(2, EDIT_TEST));
      await expect(routeCell(check(), 'failure')).toHaveAttribute('data-route-kind', 'loop');

      // And back to the empty branches, which go on with the next step.
      const cleared = await editStepThroughForm(page, EDIT_TEST, {
        onSuccess: nextOption(EDIT_DEPLOY),
        onFailure: nextFailureOption(EDIT_DEPLOY),
      });
      expect(cleared).toBe(stepSaved(EDIT_TEST, 'On Success', 'On Failure'));
      await expect(routeCell(check(), 'success')).toHaveText(routeNext(3, EDIT_DEPLOY));
      await expect(routeCell(check(), 'failure')).toHaveText(`${routeNext(3, EDIT_DEPLOY)}${ROUTE_ALSO_AFTER_FAILURE}`);
    });
    await expect(templateStepNames(page)).toHaveText([EDIT_BUILD, EDIT_TEST, EDIT_DEPLOY]);
  });

  test('a refused save shows the form again with what was typed and "Step not saved: …"', async ({ page }) => {
    const id = await openWorkflowTemplate(page, EDIT_STEPS_WORKFLOW);
    const deployId = await templateStepId(page, EDIT_DEPLOY);
    const stepId = await openEditPage(page, id, EDIT_BUILD);
    const editUrl = new RegExp(`/workflow-template/edit-step\\?id=${id}&step=${stepId}$`);

    await page.locator('#workflowstep-name').fill('');
    await fillStepForm(page, { onSuccess: stepLabel(3, EDIT_DEPLOY), position: positionEnd(stepLabel(3, EDIT_DEPLOY)) });
    // The form's own check refuses an empty name before it is sent ...
    await page.locator('#edit-step-form button[type="submit"]').click();
    await expect(page.locator('.field-workflowstep-name .help-block')).toHaveText('Name cannot be blank.');
    await expect(page).toHaveURL(editUrl);
    // ... and the server too, when the form is sent without that check.
    await Promise.all([
      page.waitForNavigation(),
      page.locator('#edit-step-form').evaluate((form) => (form as HTMLFormElement).submit()),
    ]);

    await expect(page).toHaveURL(editUrl);
    await expect(page.locator('#step-form-error')).toHaveText(`${STEP_NOT_SAVED}Name cannot be blank.`);
    await expect(page.locator('#page-content > .alert-dismissible')).toHaveCount(0);
    // What was typed and chosen is still there.
    await expect(page.locator('#workflowstep-name')).toHaveValue('');
    await expect(page.locator('#workflowstep-on_success_step_id')).toHaveValue(deployId);
    await expect(page.locator(POSITION_SELECT)).toHaveValue('end');

    // Nothing was saved.
    await page.locator('#edit-step-cancel').click();
    await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=${id}#step-${stepId}$`));
    await expect(templateStepNames(page)).toHaveText([EDIT_BUILD, EDIT_TEST, EDIT_DEPLOY]);
    await expect(routeCell(templateStepRow(page, EDIT_BUILD), 'success')).toHaveText(routeNext(2, EDIT_TEST));
  });

  test('an approval step keeps its type and has one end for On Failure; a save that changes nothing says so', async ({ page }) => {
    const id = await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const stepId = await openEditPage(page, id, ROUTES_STEPS.approve);

    await expect(page.locator('#page-content h2')).toHaveText(`Edit ${stepReference(2, ROUTES_STEPS.approve)}`);
    await expect(page.locator('#ws-step-type')).toBeDisabled();
    await expect(page.locator('#ws-step-type')).toHaveValue('approval');
    await expect(page.locator('#workflowstep-approval_rule_id option:checked')).toHaveText(GATE_RULE);
    await expect(page.locator('#ws-route-success label')).toHaveText(SUCCESS_LABEL);
    // Empty, a refused approval ends the run as failed: one end choice only.
    const failure = page.locator('#workflowstep-on_failure_step_id');
    await expect(failure.locator('option').first()).toHaveText(END_FAILS_OPTION);
    await expect(failure.locator('option[value="0"]')).toHaveCount(0);
    await expect(failure.locator('option', { hasText: /^End the workflow/ })).toHaveCount(1);
    // No Always branch: it would continue past a rejection.
    await expect(page.locator('#ws-route-always')).toBeHidden();
    await expect(page.locator('#workflowstep-on_always_step_id')).toBeDisabled();

    await submitForm(page, 'Save Step');
    await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=${id}#step-${stepId}$`));
    await expectFlash(page, 'info', stepUnchanged(ROUTES_STEPS.approve));
    await expect(changeFlash(page)).toHaveText(stepUnchanged(ROUTES_STEPS.approve));
    await expect(page.locator('#page-content > .alert-warning.alert-dismissible')).toHaveCount(0);
  });

  test('an Always branch an older version saved shows under On Success, and a deleted job template is named', async ({ page }) => {
    const id = await openWorkflowTemplate(page, LEGACY_WORKFLOW);
    const endId = await templateStepId(page, LEGACY_STEPS.end);

    // A pause step took its Always branch on Resume: the form shows it, and
    // saves it, as On Success.
    const pauseId = await openEditPage(page, id, LEGACY_STEPS.pause);
    await expect(page.locator('#ws-legacy-always-note')).toHaveText(LEGACY_ALWAYS_NOTE);
    await expect(page.locator('#ws-route-success label')).toHaveText(SUCCESS_LABEL_PAUSE);
    await expect(page.locator('#workflowstep-on_success_step_id')).toHaveValue(endId);
    await expect(page.locator('#ws-route-failure')).toBeHidden();
    await expect(page.locator('#ws-route-always')).toBeHidden();
    await page.locator('#edit-step-cancel').click();
    await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=${id}#step-${pauseId}$`));

    // The Always branch leads to the end step: it is listed there.
    await openEditPage(page, id, LEGACY_STEPS.end);
    await expect(page.locator('#edit-step-routed-by li')).toHaveText([stepLabel(3, LEGACY_STEPS.pause)]);
    await page.locator('#edit-step-cancel').click();

    // The step's job template was deleted: none is selected, and the form says why.
    await openEditPage(page, id, LEGACY_STEPS.gone);
    await expect(page.locator('#ws-template-gone-hint')).toHaveText(TEMPLATE_GONE_HINT);
    await expect(page.locator('#workflowstep-job_template_id')).toHaveValue('');
  });

  // Regression (wave 5, R5-ROUTE-2): the card listed only branches that runs
  // take, and read "No other step routes here." for a step whose removal
  // was refused for an On Success that the Always branch overrides.
  test('regression: Steps that route here names every step that keeps the step from being removed', async ({ page }) => {
    const [first, second, third] = ['e2e-routed-first', 'e2e-routed-second', 'e2e-routed-third'];
    await withNewWorkflow(page, 'e2e-routed-by-workflow', async (id) => {
      for (const name of [first, second, third]) {
        await addStepThroughForm(page, { name, type: 'job', target: JOB_TEMPLATE });
      }
      // The first step's On Success names the second, but its Always branch is taken instead.
      const saved = await editStepThroughForm(page, first, { onSuccess: stepLabel(2, second), always: stepLabel(3, third) });
      expect(saved).toBe(stepSaved(first, 'On Success', 'Always'));
      await expect(routeCell(templateStepRow(page, first), 'success')).toHaveText(ROUTE_UNUSED);

      await openEditPage(page, id, second);
      await expect(page.locator('#edit-step-routed-by li')).toHaveText([`${stepLabel(1, first)} ${ROUTED_BY_ALWAYS_WINS}`]);
      await expect(page.locator('#edit-step-routed-by [data-routed-by-note]')).toHaveText(ROUTED_BY_ALWAYS_WINS);
      await page.locator('#edit-step-cancel').click();

      // The removal is refused for the same step.
      page.once('dialog', (dialog) => dialog.accept());
      await Promise.all([
        page.waitForURL(/#workflow-steps$/),
        templateStepRow(page, second).locator('button[data-action="remove"]').click(),
      ]);
      await expectFlash(page, 'danger', stepRoutedTo(second, stepReference(1, first)));
    });
  });

  test('the edit page of a step another workflow has answers 404', async ({ page }) => {
    await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const foreignStepId = await templateStepId(page, ROUTES_STEPS.build);
    const id = await openWorkflowTemplate(page, EDIT_STEPS_WORKFLOW);

    const response = await page.goto(`/workflow-template/edit-step?id=${id}&step=${foreignStepId}`);
    expect(response?.status()).toBe(404);
    await expect(page.locator('body')).toContainText(`Workflow template #${id} has no step with ID ${foreignStepId}.`);
  });
});

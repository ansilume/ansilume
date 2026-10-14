import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  JOB_TEMPLATE,
  ROUTE_END_FAILS,
  ROUTE_END_SUCCEEDS,
  ROUTE_UNUSED,
  STEPS_BUILD,
  STEPS_CLEANUP,
  STEPS_COLUMNS,
  STEPS_WORKFLOW,
  STEP_NOT_ADDED,
  routeCell,
  routeNext,
  routeTo,
  stepReference,
  stepRoutedTo,
  templateStepRow,
  typeBadge,
} from '../../lib/workflows';

/**
 * The steps of a workflow template, on e2e-steps-workflow: never launched,
 * so no active run locks its steps (commands/E2eWorkflowGateSeeder.php). Its
 * build step's On Failure branch leads to its cleanup step.
 */
test.describe('Workflow Steps', () => {
  test('the workflow page lists the steps with every route', async ({ page }) => {
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    const table = page.locator('#workflow-steps-table');
    await expect(table.locator('thead th')).toHaveText([...STEPS_COLUMNS, 'Actions']);
    await expect(table.locator('tbody tr')).toHaveCount(2);

    const build = templateStepRow(page, STEPS_BUILD);
    await expect(typeBadge(build)).toHaveText('Job');
    await expect(build.locator('td').nth(3)).toHaveText(JOB_TEMPLATE);
    await expect(routeCell(build, 'success')).toHaveText(routeNext(2, STEPS_CLEANUP));
    await expect(routeCell(build, 'failure')).toHaveText(routeTo(2, STEPS_CLEANUP));
    await expect(routeCell(build, 'always')).toHaveText(ROUTE_UNUSED);

    // The last step's empty branches end the run, with its outcome.
    const cleanup = templateStepRow(page, STEPS_CLEANUP);
    await expect(routeCell(cleanup, 'success')).toHaveText(ROUTE_END_SUCCEEDS);
    await expect(routeCell(cleanup, 'failure')).toHaveText(ROUTE_END_FAILS);
    await expect(routeCell(cleanup, 'always')).toHaveText(ROUTE_UNUSED);
  });

  test('add step form exists', async ({ page }) => {
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    await expect(page.locator('#add-step-form')).toBeVisible();
    await expect(page.locator('#wf-active-runs-notice')).toHaveCount(0);
    await expect(page.locator('#wf-approval-steps-locked-notice')).toHaveCount(0);
  });

  // Regression: the web app parses JSON bodies, and the step type rule
  // compared loosely, so step_type true passed as a type. The step was
  // stored as "1", and a run that reached it waited there until canceled.
  // The form reads a JSON true as "1", which is no step type; the posted
  // step_order is ignored.
  test('a forged JSON add-step request with step type true is refused', async ({ page }) => {
    const id = await openWorkflowTemplate(page, STEPS_WORKFLOW);
    const steps = page.locator('#workflow-steps-table tbody tr');
    await expect(steps).toHaveCount(2);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');

    const response = await page.request.post(`/workflow-template/add-step?id=${id}`, {
      headers: { 'X-CSRF-Token': csrf ?? '' },
      data: { WorkflowStep: { name: 'e2e-json-true-type', step_type: true, step_order: 10 } },
    });

    // The action redirects to the workflow, which shows the refusal.
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain(`${STEP_NOT_ADDED}Step Type is invalid.`);
    await page.reload();
    await expect(steps).toHaveCount(2);
    await expect(page.locator('#workflow-steps-table')).not.toContainText('e2e-json-true-type');
  });

  // A run follows the routes of the live steps: removing a step that another
  // step routes to would fail the run at that route. Edit those steps first
  // (workflow-templates/edit-step.spec.ts).
  test('a step that another step routes to is not removed', async ({ page }) => {
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForURL(/#workflow-steps$/),
      templateStepRow(page, STEPS_CLEANUP).locator('button[data-action="remove"]').click(),
    ]);

    await expectFlash(page, 'danger', stepRoutedTo(STEPS_CLEANUP, stepReference(1, STEPS_BUILD)));
    await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(2);
    await expect(templateStepRow(page, STEPS_CLEANUP)).toHaveCount(1);
    await expect(routeCell(templateStepRow(page, STEPS_BUILD), 'failure')).toHaveText(routeTo(2, STEPS_CLEANUP));
  });
});

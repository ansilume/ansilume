import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { openWorkflowTemplate } from '../../lib/triggers';
import { ROUTE_NEXT_STEP, STEPS_BUILD, STEPS_CLEANUP, STEPS_WORKFLOW, templateStepRow } from '../../lib/workflows';

/**
 * The steps of a workflow template, on e2e-steps-workflow: never launched,
 * so no active run locks its steps (commands/E2eWorkflowGateSeeder.php). Its
 * build step's On Failure branch leads to its cleanup step.
 */
test.describe('Workflow Steps', () => {
  test('the workflow page lists the steps with every route', async ({ page }) => {
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    const table = page.locator('#workflow-steps-table');
    await expect(table.locator('thead th')).toHaveText(['#', 'Name', 'Type', 'Target', 'On Success', 'On Failure', 'Always', 'Actions']);
    await expect(table.locator('tbody tr')).toHaveCount(2);

    const build = templateStepRow(page, STEPS_BUILD);
    await expect(build.locator('td').nth(2)).toHaveText('Job');
    await expect(build.locator('td').nth(3)).toHaveText('e2e-template');
    await expect(build.locator('td[data-route="success"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(build.locator('td[data-route="failure"]')).toHaveText(`#20 ${STEPS_CLEANUP}`);
    await expect(build.locator('td[data-route="always"]')).toHaveText('—');

    const cleanup = templateStepRow(page, STEPS_CLEANUP);
    await expect(cleanup.locator('td[data-route="success"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(cleanup.locator('td[data-route="failure"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(cleanup.locator('td[data-route="always"]')).toHaveText('—');
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
    expect(await response.text()).toContain('Step not added: Step Type must be a string.');
    await page.reload();
    await expect(steps).toHaveCount(2);
    await expect(page.locator('#workflow-steps-table')).not.toContainText('e2e-json-true-type');
  });

  // A run follows the routes of the live steps: removing a step that another
  // step routes to would fail the run at that route.
  test('a step that another step routes to is not removed', async ({ page }) => {
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    page.once('dialog', (dialog) => dialog.accept());
    await templateStepRow(page, STEPS_CLEANUP).locator('form[action*="/workflow-template/remove-step"] button').click();

    await expectFlash(
      page,
      'danger',
      `Step "${STEPS_CLEANUP}" was not removed: other steps route to it (#10 "${STEPS_BUILD}"). `
        + 'Remove those steps first, and add them again with other routes if you still need them.',
    );
    await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(2);
    await expect(templateStepRow(page, STEPS_CLEANUP)).toHaveCount(1);
    await expect(templateStepRow(page, STEPS_BUILD).locator('td[data-route="failure"]')).toHaveText(`#20 ${STEPS_CLEANUP}`);
  });
});

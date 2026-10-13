import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  EMPTY_WORKFLOW,
  GATE_RULE,
  STEPS_BUILD,
  STEPS_CLEANUP,
  STEPS_WORKFLOW,
  STEP_ORDER_HINT,
  STEP_ORDER_INPUT,
  STEP_ORDER_PLACEHOLDER,
  addStepThroughForm,
  runStepRow,
  templateStepNames,
  templateStepNumbers,
  templateStepRow,
} from '../../lib/workflows';

/**
 * Where the add-step form puts a new step.
 *
 * Regression (since 2.3.15): every add and move renumbers the steps 10, 20,
 * 30…, but the form still pre-filled Step Order with the number of steps
 * (0, 1, 2…). From the second step on, a step added with that value sorted
 * before #10, landed first and became the start step: Build, Test and Deploy,
 * added in turn, ran as Deploy, Test, Build, and a job step added after an
 * approval step ran before it, without approval. Now Step Order is empty by
 * default, and an empty or left out Step Order adds the step after the last
 * one; a typed number places the step as before, up to the largest number
 * the database holds.
 *
 * e2e-admin, who may add approval steps, adds steps to workflows the tests
 * create through the form and delete again; the form itself is checked on
 * e2e-empty-workflow and e2e-steps-workflow, which stay as seeded
 * (commands/E2eWorkflowGateSeeder.php). Operators and viewers:
 * workflow-templates/rbac.spec.ts.
 */
const JOB_TEMPLATE = 'e2e-template';

/** The largest step order the database column holds (models/WorkflowStep.php MAX_STEP_ORDER). */
const MAX_STEP_ORDER = '2147483647';

/** Creates the workflow template `name` through the form; its ID. Its page is open then. */
async function createWorkflow(page: Page, name: string): Promise<string> {
  await page.goto('/workflow-template/create');
  await page.locator('#workflowtemplate-name').fill(name);
  await submitForm(page);
  await expectFlash(page, 'success', `Workflow template "${name}" created.`);
  await expect(page).toHaveURL(/\/workflow-template\/view\?id=\d+$/);
  await expect(page.locator('#page-content')).toContainText('No steps defined yet.');
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/** Deletes the workflow template #`id` named `name` from its page; that cancels its active runs. */
async function deleteWorkflow(page: Page, id: string, name: string): Promise<void> {
  await page.goto(`/workflow-template/view?id=${id}`);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-template\/index/),
    page.locator('#wf-delete-form button[type="submit"]').click(),
  ]);
  await expectFlash(page, 'success', `Workflow template "${name}" deleted.`);
}

/**
 * Runs `use` on the new workflow template `name`, whose page is open then,
 * and deletes it afterwards. When `use` fails, the workflow is still deleted
 * if that works; the E2E teardown removes what is left.
 */
async function withNewWorkflow(page: Page, name: string, use: (id: string) => Promise<void>): Promise<void> {
  const id = await createWorkflow(page, name);
  try {
    await use(id);
  } catch (error) {
    await deleteWorkflow(page, id, name).catch(() => undefined);
    throw error;
  }
  await deleteWorkflow(page, id, name);
}

/**
 * The Step Order field of the open page is empty, with the placeholder "At
 * the end" and no negative numbers, and labelled as the hint names it.
 */
async function expectEmptyStepOrder(page: Page): Promise<void> {
  const order = page.locator(STEP_ORDER_INPUT);
  await expect(order).toHaveAttribute('name', 'WorkflowStep[step_order]');
  await expect(order).toHaveValue('');
  await expect(order).toHaveAttribute('placeholder', STEP_ORDER_PLACEHOLDER);
  await expect(order).toHaveAttribute('min', '0');
  // The hint names the field by its label.
  await expect(page.locator('label[for="workflowstep-step_order"]')).toHaveText('Step Order');
}

test.describe('Step order of new steps (admin)', () => {
  test('regression: steps added with the default order ran in reverse', async ({ page }) => {
    const steps = ['e2e-order-build', 'e2e-order-test', 'e2e-order-deploy'];
    await withNewWorkflow(page, 'e2e-default-order-workflow', async () => {
      for (const [index, name] of steps.entries()) {
        // Step Order is left as the form shows it.
        await addStepThroughForm(page, { name, type: 'job', target: JOB_TEMPLATE });
        // The new step lands after the steps added before it.
        await expect(templateStepNames(page)).toHaveText(steps.slice(0, index + 1));
      }
      // The step added first carries the lowest number: the run starts with it.
      await expect(templateStepNumbers(page)).toHaveText(['10', '20', '30']);
      // With steps, the field stays empty: it was pre-filled with their number.
      await expectEmptyStepOrder(page);
    });
  });

  test('regression: a job step added with the default order ran before the approval step', async ({ page }) => {
    const workflow = 'e2e-default-order-approval-workflow';
    const approve = 'e2e-order-approve';
    const jobs = ['e2e-order-migrate', 'e2e-order-release'];
    await withNewWorkflow(page, workflow, async () => {
      await addStepThroughForm(page, { name: approve, type: 'approval', target: GATE_RULE });
      for (const [index, name] of jobs.entries()) {
        // Step Order is left as the form shows it.
        await addStepThroughForm(page, { name, type: 'job', target: JOB_TEMPLATE });
        // The job step lands after the approval step, which stays first.
        await expect(templateStepNames(page)).toHaveText([approve, ...jobs.slice(0, index + 1)]);
      }
      await expect(templateStepNumbers(page)).toHaveText(['10', '20', '30']);
      await expect(templateStepRow(page, approve).locator('td').nth(2)).toHaveText('Approval');

      // A run starts with the approval step and waits there: no job step has started.
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

  test('the Step Order field is empty with the placeholder "At the end", and the hint below the steps says where a step goes', async ({ page }) => {
    // Without steps: no steps table, and so no hint.
    await openWorkflowTemplate(page, EMPTY_WORKFLOW);
    await expect(page.locator('#page-content')).toContainText('No steps defined yet.');
    await expectEmptyStepOrder(page);
    await expect(page.getByText(STEP_ORDER_HINT)).toHaveCount(0);

    // With steps, the field stays empty too, and the hint follows the steps table.
    await openWorkflowTemplate(page, STEPS_WORKFLOW);
    await expect(templateStepNames(page)).toHaveText([STEPS_BUILD, STEPS_CLEANUP]);
    await expectEmptyStepOrder(page);
    await expect(page.locator('#page-content').getByText(STEP_ORDER_HINT, { exact: true })).toHaveCount(1);
    const hint = page.locator('#workflow-steps-table + *');
    await expect(hint).toBeVisible();
    await expect(hint).toHaveText(STEP_ORDER_HINT);
  });

  // The form always sends Step Order; a hand-made request that leaves it out
  // got the column's default 0, so its step went first too.
  test('a step posted without a Step Order lands at the end too', async ({ page }) => {
    const [first, posted] = ['e2e-order-first', 'e2e-order-posted'];
    await withNewWorkflow(page, 'e2e-missing-order-workflow', async (id) => {
      await addStepThroughForm(page, { name: first, type: 'job', target: JOB_TEMPLATE });
      // A real form post with a valid CSRF token, without the field.
      await page.goto('/workflow-template/index');
      await forgePost(page, `/workflow-template/add-step?id=${id}`, {
        'WorkflowStep[name]': posted,
        'WorkflowStep[step_type]': 'pause',
      }, /\/workflow-template\/view/);
      await expectFlash(page, 'success', `Step "${posted}" added.`);
      await expect(templateStepNames(page)).toHaveText([first, posted]);
      await expect(templateStepNumbers(page)).toHaveText(['10', '20']);
    });
  });

  // Regression: a Step Order larger than the column holds passed every
  // check, and the database refused it with an error page (500).
  test('regression: a Step Order too large for the database answered 500', async ({ page }) => {
    const name = 'e2e-order-too-large';
    const tooLarge = `Step Order must be no greater than ${MAX_STEP_ORDER}.`;
    await withNewWorkflow(page, 'e2e-large-order-workflow', async (id) => {
      // The form refuses it before it is sent.
      await page.locator('#workflowstep-name').fill(name);
      await page.locator('#ws-step-type').selectOption('pause');
      await page.locator(STEP_ORDER_INPUT).fill('3000000000');
      await page.locator('#add-step-form button[type="submit"]').click();
      await expect(page.locator('#add-step-form .field-workflowstep-step_order').locator('.help-block, .invalid-feedback'))
        .toHaveText(tooLarge);
      await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=${id}$`));

      // The server refuses it too: a real form post with a valid CSRF token.
      await page.goto('/workflow-template/index');
      await forgePost(page, `/workflow-template/add-step?id=${id}`, {
        'WorkflowStep[name]': name,
        'WorkflowStep[step_type]': 'pause',
        'WorkflowStep[step_order]': '3000000000',
      }, /\/workflow-template\/view/);
      await expectFlash(page, 'danger', `Step not added: ${tooLarge}`);
      await expect(page.locator('#page-content')).toContainText('No steps defined yet.');
    });
  });

  // What the hint says about typed numbers and ▲ / ▼ holds: a typed number
  // places the step as before the fix.
  test('a typed Step Order puts the step between two others, and ▲ / ▼ move a step by one place', async ({ page }) => {
    const [build, check, lint, deploy] = ['e2e-typed-build', 'e2e-typed-check', 'e2e-typed-lint', 'e2e-typed-deploy'];
    await withNewWorkflow(page, 'e2e-typed-order-workflow', async () => {
      await addStepThroughForm(page, { name: build, type: 'job', target: JOB_TEMPLATE, order: '10' });
      await addStepThroughForm(page, { name: deploy, type: 'job', target: JOB_TEMPLATE, order: '20' });
      // A number between #10 and #20.
      await addStepThroughForm(page, { name: check, type: 'job', target: JOB_TEMPLATE, order: '15' });
      await expect(templateStepNames(page)).toHaveText([build, check, deploy]);
      await expect(templateStepNumbers(page)).toHaveText(['10', '20', '30']);
      // The number of an existing step: right after that step.
      await addStepThroughForm(page, { name: lint, type: 'job', target: JOB_TEMPLATE, order: '10' });
      await expect(templateStepNames(page)).toHaveText([build, lint, check, deploy]);
      await expect(templateStepNumbers(page)).toHaveText(['10', '20', '30', '40']);

      const rows = page.locator('#workflow-steps-table tbody tr');
      await expect(rows.first().locator('[data-action="move-up"]')).toBeDisabled();
      await expect(rows.last().locator('[data-action="move-down"]')).toBeDisabled();
      await Promise.all([
        page.waitForNavigation(),
        templateStepRow(page, deploy).locator('[data-action="move-up"]').click(),
      ]);
      await expectFlash(page, 'success', `Step "${deploy}" moved up.`);
      await expect(templateStepNames(page)).toHaveText([build, lint, deploy, check]);
      await Promise.all([
        page.waitForNavigation(),
        templateStepRow(page, build).locator('[data-action="move-down"]').click(),
      ]);
      await expectFlash(page, 'success', `Step "${build}" moved down.`);
      await expect(templateStepNames(page)).toHaveText([lint, build, deploy, check]);
      await expect(templateStepNumbers(page)).toHaveText(['10', '20', '30', '40']);
    });
  });
});

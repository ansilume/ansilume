import { test, expect, Locator, Page } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

/**
 * A workflow template that was deleted is named "<name> (deleted)", muted
 * and without a link, wherever its runs and their approval requests name it
 * (views/workflow-job/_workflow-name.php): the run page, the Workflow Jobs
 * list, and the For cell of the approval list and of the request page. Its
 * page answers 404, so a link would lead nowhere.
 *
 * The fixture is built here through the UI, under a name of its own: a
 * workflow with one approval step on e2e-gate-rule (e2e-admin and
 * e2e-operator approve; commands/E2eWorkflowGateSeeder.php). e2e-admin
 * launches it, so the run waits for e2e-operator's approval, then deletes
 * the workflow, which cancels the run and its request. A test that fails
 * before the delete deletes the workflow afterwards, so no run is left
 * waiting.
 */

/** Texts::WORKFLOW_DELETED, after the name of a deleted workflow. */
const DELETED = '(deleted)';

/** The approval rule of the approval step (seeded). */
const GATE_RULE = 'e2e-gate-rule';

/** The approval step of the workflow built here. */
const APPROVE_STEP = 'e2e-deleted-links-approve';

test.describe('Links to a deleted workflow template', () => {
  let workflowId = '';
  let deleted = false;

  test.afterEach(async ({ page }) => {
    if (workflowId !== '' && !deleted) {
      await deleteWorkflow(page, workflowId);
    }
    workflowId = '';
    deleted = false;
  });

  // regression: the run page and the approvals' For cell linked a deleted
  // workflow, whose page answers 404, and nothing said it was deleted
  // (PHPUnit: DeletedWorkflowNamesTest).
  test('a deleted workflow reads "(deleted)" without a link on the run page, the run list and the approvals', async ({ page }) => {
    const name = `e2e-deleted-links-${Date.now()}`;
    workflowId = await createWorkflowWithApprovalStep(page, name);

    // Launched, the run waits at its approval step; everything links the live workflow.
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForURL(/\/workflow-job\/view\?id=\d+$/),
      page.locator('#page-content form[action*="/workflow-template/launch"] button').click(),
    ]);
    const runId = new URL(page.url()).searchParams.get('id') ?? '';
    expect(runId).toMatch(/^\d+$/);
    const workflowLink = page.locator('#wj-workflow a');
    await expect(workflowLink).toHaveText(name);
    await expect(workflowLink).toHaveAttribute('href', `/workflow-template/view?id=${workflowId}`);
    const requestLink = page.locator('#wj-steps-table [data-wjs-request-link]');
    await expect(requestLink).toHaveText(/^Request #\d+$/);
    const requestId = ((await requestLink.textContent()) ?? '').trim().replace('Request #', '');
    const forCell = page.locator(`tr[data-request-id="${requestId}"] td[data-approval-for]`);
    await page.goto('/approval/index');
    await expect(forCell.locator(`a[data-for-workflow="${workflowId}"]`)).toHaveText(name);

    // The delete cancels the run and its request.
    deleted = await deleteWorkflow(page, workflowId);
    expect(deleted).toBe(true);
    await expectFlash(page, 'success', `Workflow template "${name}" deleted. 1 running run(s) were canceled.`);
    expect((await page.goto(`/workflow-template/view?id=${workflowId}`))?.status()).toBe(404);

    // The run page: the name, marked deleted, without a link.
    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(page.locator('#wj-status')).toHaveText('Canceled');
    await expectDeletedName(page.locator('#wj-workflow'), name);

    // The Workflow Jobs list, filtered by the workflow (deleted ones included).
    await page.goto(`/workflow-job/index?workflow_template_id=${workflowId}`);
    const row = page.locator('#page-content table tbody tr').filter({ has: page.locator(`a[href="/workflow-job/view?id=${runId}"]`) });
    await expect(row).toHaveCount(1);
    await expectDeletedName(row.locator('td[data-run-workflow]'), name);

    // The approval list and the request page: the workflow without a link, the run still linked.
    const forText = `${name} ${DELETED} › ${APPROVE_STEP} (Workflow Job #${runId})`;
    await page.goto('/approval/index');
    await expect(forCell).toHaveText(forText);
    await expect(forCell.locator('a[data-for-workflow]')).toHaveCount(0);
    await expect(forCell.locator('.text-muted')).toHaveText(DELETED);
    await expect(forCell.locator(`a[data-for-run="${runId}"]`)).toHaveAttribute('href', `/workflow-job/view?id=${runId}`);
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-for')).toHaveText(forText);
    await expect(page.locator('#approval-for a[data-for-workflow]')).toHaveCount(0);
    await expect(page.locator('#approval-for a[data-for-run]')).toHaveCount(1);
  });
});

/**
 * Creates the workflow `name` with the approval step APPROVE_STEP on
 * GATE_RULE through the UI; its id. The page of the workflow stays open.
 */
async function createWorkflowWithApprovalStep(page: Page, name: string): Promise<string> {
  await page.goto('/workflow-template/create');
  await page.locator('#workflowtemplate-name').fill(name);
  await Promise.all([
    page.waitForURL(/\/workflow-template\/view\?id=\d+/),
    page.locator('#workflow-template-form button[type="submit"]').click(),
  ]);
  const id = new URL(page.url()).searchParams.get('id') ?? '';
  expect(id).toMatch(/^\d+$/);
  await page.locator('#workflowstep-name').fill(APPROVE_STEP);
  await page.locator('#ws-step-type').selectOption('approval');
  await page.locator('#workflowstep-approval_rule_id').selectOption({ label: GATE_RULE });
  await Promise.all([
    page.waitForURL(/\/workflow-template\/view\?id=\d+/),
    page.locator('#add-step-form button[type="submit"]').click(),
  ]);
  await expect(page.locator('#workflow-steps-table tbody tr[data-step-type="approval"]')).toHaveCount(1);
  return id;
}

/**
 * Deletes the workflow `id` with the Delete button of its page; whether it
 * was there to delete. The page then shows the workflow list.
 */
async function deleteWorkflow(page: Page, id: string): Promise<boolean> {
  const response = await page.goto(`/workflow-template/view?id=${id}`);
  if (response?.status() !== 200) {
    return false;
  }
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-template\/index/),
    page.locator('#wf-delete-form button[type="submit"]').click(),
  ]);
  return true;
}

/** The cell names the workflow `name`, marked deleted and muted, without a link. */
async function expectDeletedName(cell: Locator, name: string): Promise<void> {
  await expect(cell).toHaveText(`${name} ${DELETED}`);
  await expect(cell.locator('.text-muted')).toHaveText(DELETED);
  await expect(cell.locator('a')).toHaveCount(0);
}

import { test, expect, Page } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { HOST_PROJECT, HOST_RUNNER_GROUP } from '../../lib/inventories';
import { deleteOpenTemplate } from '../../lib/job-templates';
import { SEEDED_JOB_TEMPLATE, queuedJobOf } from '../../lib/jobs';
import { BETA_APPROVAL_STEP, BETA_APPROVAL_WORKFLOW, cancelJobIfActive, onlyRunId, stepRequestId } from '../../lib/runs';
import { runStepRow } from '../../lib/workflows';
import {
  NO_INVENTORY,
  cancelJob,
  flash,
  jobInventoryCell,
  launchTemplate,
  linkedInventoryId,
  relaunchJob,
  relaunched,
  requestJobId,
} from '../../lib/approvals';

/**
 * The Inventory row of the job page (#detail-inventory,
 * views/job/_inventory.php): the inventory the job was launched with, as its
 * launch record names it. Its name at the launch (a re-launch is a launch
 * of its own), linked to the inventory's page while the inventory exists,
 * and "(deleted)" once it is gone; "—" for a job without one: a job without
 * a launch record, and the placeholder of a workflow approval step.
 *
 * The first test makes its own inventory and job template, so that it may
 * rename and delete the inventory, and removes both. The template uses
 * e2e-host-gate-project, which has no local path, and
 * e2e-host-gate-runner-group, which no runner serves, so its job stays
 * queued until the test cancels it (commands/E2eInventoryGateSeeder.php).
 */

/** The inventory this file creates, renames and deletes; static, one host at an address reserved for documentation. */
const INVENTORY = 'e2e-job-launch-inv';
const RENAMED_INVENTORY = 'e2e-job-launch-inv-renamed';
const CONTENT = 'all:\n  hosts:\n    e2e-job-launch-host:\n      ansible_host: 192.0.2.60\n';

/** The job template this file creates on INVENTORY and deletes. */
const TEMPLATE = 'e2e-job-launch-tmpl';

/**
 * The inventory TEMPLATE moves to, so that INVENTORY can be deleted: a job
 * template keeps its inventory even when it is deleted. e2e-template's
 * inventory, which has no approval rule.
 */
const OTHER_INVENTORY = 'e2e-inventory';

/** The ID in the URL of the open page (…?id=N). */
function openId(page: Page): string {
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/** Creates INVENTORY through the form; its ID. Its page is open then. */
async function createInventory(page: Page): Promise<string> {
  await page.goto('/inventory/create');
  await page.locator('#inventory-name').fill(INVENTORY);
  await expect(page.locator('#inventory-type')).toHaveValue('static');
  // The textarea is replaced by a CodeMirror editor (yaml-editor.js) and
  // hidden: set the form value on the textarea itself.
  await page.locator('textarea[data-yaml-editor]').evaluate((element, value) => {
    (element as HTMLTextAreaElement).value = value;
  }, CONTENT);
  await Promise.all([
    page.waitForURL(/\/inventory\/view\?id=\d+$/),
    page.getByRole('button', { name: 'Create Inventory' }).click(),
  ]);
  await expectFlash(page, 'success', `Inventory "${INVENTORY}" created.`);
  return openId(page);
}

/** Creates TEMPLATE on the inventory #`inventoryId` through the form; its ID. Its page is open then. */
async function createTemplate(page: Page, inventoryId: string): Promise<string> {
  await page.goto('/job-template/create');
  await page.locator('#jobtemplate-name').fill(TEMPLATE);
  await page.locator('#jobtemplate-project_id').selectOption({ label: HOST_PROJECT });
  await page.locator('#jobtemplate-playbook').fill('site.yml');
  await page.locator('#jobtemplate-inventory_id').selectOption(inventoryId);
  await page.locator('#jobtemplate-runner_group_id').selectOption({ label: HOST_RUNNER_GROUP });
  await Promise.all([
    page.waitForURL(/\/job-template\/view\?id=\d+$/),
    page.getByRole('button', { name: 'Create Template' }).click(),
  ]);
  await expectFlash(page, 'success', `Template "${TEMPLATE}" created.`);
  return openId(page);
}

/** Gives the inventory #`id` the name `name` through its edit form. */
async function renameInventory(page: Page, id: string, name: string): Promise<void> {
  await page.goto(`/inventory/update?id=${id}`);
  await page.locator('#inventory-name').fill(name);
  await Promise.all([
    page.waitForURL(new RegExp(`/inventory/view\\?id=${id}$`)),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
  await expectFlash(page, 'success', `Inventory "${name}" updated.`);
}

/** Moves the job template #`id` to the inventory labelled `label` through its edit form. */
async function moveTemplateTo(page: Page, id: string, label: string): Promise<void> {
  await page.goto(`/job-template/update?id=${id}`);
  await page.locator('#jobtemplate-inventory_id').selectOption({ label });
  await Promise.all([
    page.waitForURL(new RegExp(`/job-template/view\\?id=${id}$`)),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
  await expectFlash(page, 'success', `Template "${TEMPLATE}" updated.`);
}

/** Deletes the inventory #`id`, named `name`, from its page; the inventory list opens. */
async function deleteInventory(page: Page, id: string, name: string): Promise<void> {
  await page.goto(`/inventory/view?id=${id}`);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/inventory\/index/),
    page.locator(`#page-content form[action="/inventory/delete?id=${id}"] button[type="submit"]`).click(),
  ]);
  await expectFlash(page, 'success', `Inventory "${name}" deleted.`);
}

/** Deletes the job template #`id` from its page, which keeps its jobs. */
async function deleteTemplate(page: Page, id: string): Promise<void> {
  await page.goto(`/job-template/view?id=${id}`);
  await deleteOpenTemplate(page);
  await expectFlash(page, 'success', `Template "${TEMPLATE}" deleted: `);
}

/** The Inventory row of job #`jobId` names `name`, marked deleted, without a link. */
async function expectDeletedInventory(page: Page, jobId: string, name: string): Promise<void> {
  await page.goto(`/job/view?id=${jobId}`);
  await expect(jobInventoryCell(page)).toHaveText(`${name} (deleted)`);
  await expect(jobInventoryCell(page).locator('span.text-muted')).toHaveText('(deleted)');
  await expect(jobInventoryCell(page).locator('a')).toHaveCount(0);
}

test.describe('Job page: the inventory a job was launched with', () => {
  test('a job names its inventory by the name at its launch, links it while it exists, and marks it deleted', async ({ page }) => {
    let inventoryId = '';
    let templateId = '';
    let jobId = '';
    let relaunchedId = '';
    let inventoryName = INVENTORY;
    let inventoryGone = false;
    let templateGone = false;
    try {
      inventoryId = await createInventory(page);
      templateId = await createTemplate(page, inventoryId);

      // Neither the template nor the inventory has a rule: the job is queued.
      jobId = await launchTemplate(page, TEMPLATE);
      await expect(flash(page, 'success')).toHaveText(`Job #${jobId} queued.`);
      expect(await linkedInventoryId(jobInventoryCell(page), INVENTORY), 'the inventory the job was launched with').toBe(inventoryId);
      await expect(jobInventoryCell(page).locator('.text-muted')).toHaveCount(0);

      // Renamed since: the job keeps the name it was launched with, and the link.
      await renameInventory(page, inventoryId, RENAMED_INVENTORY);
      inventoryName = RENAMED_INVENTORY;
      await page.goto(`/job/view?id=${jobId}`);
      expect(await linkedInventoryId(jobInventoryCell(page), INVENTORY)).toBe(inventoryId);
      await cancelJob(page);

      // A re-launch is a launch of its own: its job records the name the
      // inventory has now. Nothing gates it, so the flash says it is queued.
      relaunchedId = await relaunchJob(page);
      await expect(flash(page, 'success')).toHaveText(relaunched(relaunchedId));
      expect(await linkedInventoryId(jobInventoryCell(page), RENAMED_INVENTORY)).toBe(inventoryId);
      await cancelJob(page);

      // Deleted since (the template moved to another inventory first): each
      // job keeps the name at its launch, marked deleted, without a link.
      await moveTemplateTo(page, templateId, OTHER_INVENTORY);
      await deleteInventory(page, inventoryId, inventoryName);
      inventoryGone = true;
      await expectDeletedInventory(page, jobId, INVENTORY);
      await expectDeletedInventory(page, relaunchedId, RENAMED_INVENTORY);

      await deleteTemplate(page, templateId);
      templateGone = true;
    } finally {
      await cancelJobIfActive(page, relaunchedId);
      await cancelJobIfActive(page, jobId);
      if (templateId !== '' && !templateGone && !inventoryGone) {
        await moveTemplateTo(page, templateId, OTHER_INVENTORY).catch(() => undefined);
      }
      if (inventoryId !== '' && !inventoryGone) {
        await deleteInventory(page, inventoryId, inventoryName).catch(() => undefined);
      }
      if (templateId !== '' && !templateGone) {
        await deleteTemplate(page, templateId).catch(() => undefined);
      }
    }
  });

  test('a job without a launch record names no inventory', async ({ page, request, baseURL }) => {
    // A job row written without a launch (controllers/E2eController.php
    // actionCreateCancelableJob), as older jobs of tests and seeders are.
    const jobId = await queuedJobOf(request, baseURL, SEEDED_JOB_TEMPLATE);
    try {
      await page.goto(`/job/view?id=${jobId}`);
      await expect(jobInventoryCell(page)).toHaveText(NO_INVENTORY);
      await expect(jobInventoryCell(page).locator('a')).toHaveCount(0);
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  test('the placeholder of a workflow approval step names no inventory', async ({ page }) => {
    // commands/E2eWorkflowScopingSeeder.php: the run of e2e-beta-approval-wf
    // waits at its approval step, whose request holds the placeholder job.
    const runId = await onlyRunId(page, BETA_APPROVAL_WORKFLOW);
    await page.goto(`/workflow-job/view?id=${runId}`);
    const requestId = await stepRequestId(runStepRow(page, BETA_APPROVAL_STEP));
    await page.goto(`/approval/view?id=${requestId}`);
    const jobId = await requestJobId(page);

    await page.goto(`/job/view?id=${jobId}`);
    await expect(jobInventoryCell(page)).toHaveText(NO_INVENTORY);
    await expect(jobInventoryCell(page).locator('a')).toHaveCount(0);
  });
});

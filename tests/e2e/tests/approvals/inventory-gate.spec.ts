import { test, expect, Page } from '@playwright/test';
import { templateId } from '../../lib/helpers';
import { HOST_INVENTORY, HOST_RULE, HOST_TEMPLATE } from '../../lib/inventories';
import { cancelJobIfActive, forJobTemplate } from '../../lib/runs';
import { TRIGGER_HEADER, generateTriggerToken, openJobTemplate, revokeTriggerTokenIfPresent } from '../../lib/triggers';
import {
  LAUNCHER_REFUSED,
  REQUIRED_BY,
  asOperator,
  cancelJob,
  decide,
  decisionForms,
  flash,
  jobInventoryCell,
  launchTemplate,
  linkedInventoryId,
  relaunchJob,
  relaunchedWaiting,
  requestInventoryCell,
  requestOfJob,
  requestStatus,
  requiredByCell,
  ruleOfInventory,
  waitingForApproval,
} from '../../lib/approvals';

/**
 * An approval rule on an inventory gates every launch of every job template
 * that uses it, from the launch page, a re-launch and the job trigger alike;
 * the request says that the inventory required it.
 *
 * Fixtures (commands/E2eInventoryGateSeeder.php): e2e-host-gated-template
 * has no rule of its own; its inventory, e2e-gated-inventory, has
 * e2e-host-gate-rule, on which e2e-admin and e2e-operator approve. No runner
 * serves its runner group, so an approved job stays queued. e2e-admin, the
 * user of this project, launches; the launcher never decides, so
 * e2e-operator does. Every job a test launches is canceled in its finally;
 * the next seed deletes the jobs of the fixtures as well.
 */

/** The job template launch page's Launch Job button. */
async function submitLaunch(page: Page): Promise<string> {
  await Promise.all([
    page.waitForURL(/\/job\/view\?id=\d+$/),
    page.locator('#launch-form button[type="submit"]').click(),
  ]);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/** The status badge of the open job page. */
function jobStatus(page: Page) {
  return page.locator('#status-badge');
}

test.describe('Approval rules on inventories: a launch waits for the inventory\'s rule', () => {
  test('a launch of a job template on a gated inventory waits, and the job names the inventory it was launched with', async ({ page }) => {
    const jobId = await launchTemplate(page, HOST_TEMPLATE);
    try {
      await expect(flash(page, 'info')).toHaveText(waitingForApproval(jobId));
      await expect(jobStatus(page)).toHaveText('Awaiting Approval');
      const inventoryId = await linkedInventoryId(jobInventoryCell(page), HOST_INVENTORY);

      // The link opens the inventory, which names the rule.
      await jobInventoryCell(page).locator('a').click();
      await expect(page).toHaveURL(new RegExp(`/inventory/view\\?id=${inventoryId}$`));
      await expect(page.locator('#inventory-approval-rule')).toContainText(HOST_RULE);
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  test('its request says that the inventory required it, names the inventory and refuses the launcher', async ({ page }) => {
    const jobId = await launchTemplate(page, HOST_TEMPLATE);
    try {
      const inventoryId = await linkedInventoryId(jobInventoryCell(page), HOST_INVENTORY);
      const requestId = await requestOfJob(page, jobId);
      await page.goto(`/approval/view?id=${requestId}`);
      await expect(requestStatus(page)).toHaveText('Pending');
      await expect(requiredByCell(page)).toHaveText(REQUIRED_BY.inventory);
      expect(await linkedInventoryId(requestInventoryCell(page), HOST_INVENTORY), 'the inventory the job was launched with').toBe(inventoryId);
      await expect(page.locator('#approval-rule a')).toHaveText(HOST_RULE);
      await expect(page.locator('#approval-for')).toHaveText(forJobTemplate(HOST_TEMPLATE));
      await expect(page.locator('#approval-launched-by')).toHaveText('e2e-admin');

      // The launcher never decides on their own job, whatever required the request.
      await expect(page.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED);
      await expect(decisionForms(page)).toHaveCount(0);
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  test('e2e-operator approves, and the job waits for a runner', async ({ page, browser }, testInfo) => {
    const jobId = await launchTemplate(page, HOST_TEMPLATE);
    try {
      const requestId = await requestOfJob(page, jobId);
      await asOperator(browser, testInfo, async (operator) => {
        await operator.goto(`/approval/view?id=${requestId}`);
        await expect(requiredByCell(operator)).toHaveText(REQUIRED_BY.inventory);
        await expect(operator.locator('#approval-decision-note')).toHaveCount(0);
        await decide(operator, 'approve');
        await expect(flash(operator, 'success')).toHaveText('Approval recorded.');
        await expect(requestStatus(operator)).toHaveText('Approved');
      });

      // Approved: queued, and no runner serves the template's runner group.
      await page.goto(`/job/view?id=${jobId}`);
      await expect(jobStatus(page)).toHaveText('Queued');
      await linkedInventoryId(jobInventoryCell(page), HOST_INVENTORY);
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  test('a check-mode launch waits for approval too', async ({ page }) => {
    await page.goto(`/job-template/launch?id=${await templateId(page, HOST_TEMPLATE)}`);
    await page.locator('#override-check-mode').check();
    const jobId = await submitLaunch(page);
    try {
      await expect(flash(page, 'info')).toHaveText(waitingForApproval(jobId));
      await expect(jobStatus(page)).toHaveText('Awaiting Approval');
      await expect(page.locator('#page-content h2 .badge', { hasText: 'Dry Run' })).toBeVisible();
      const requestId = await requestOfJob(page, jobId);
      await page.goto(`/approval/view?id=${requestId}`);
      await expect(requiredByCell(page)).toHaveText(REQUIRED_BY.inventory);
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  test('the approval list and the dashboard name the inventory in the Rule cell', async ({ page }) => {
    const jobId = await launchTemplate(page, HOST_TEMPLATE);
    try {
      const requestId = await requestOfJob(page, jobId);
      // The list is newest first: the new request is on its first page.
      await page.goto('/approval/index?status=pending');
      const row = page.locator(`#approval-requests-table tr[data-request-id="${requestId}"]`);
      await expect(row.locator('td[data-approval-rule]')).toHaveText(ruleOfInventory(HOST_RULE, HOST_INVENTORY));
      await expect(row.locator('td[data-approval-for]')).toHaveText(forJobTemplate(HOST_TEMPLATE));

      // The dashboard lists the newest pending requests.
      await page.goto('/');
      const pending = page.locator(`#dashboard-pending-approvals tr[data-request-id="${requestId}"]`);
      await expect(pending.locator('td[data-approval-rule]')).toHaveText(ruleOfInventory(HOST_RULE, HOST_INVENTORY));
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  // The trigger runs as the user who generated its token, e2e-admin: the
  // job waits, and the answer says so (controllers/TriggerController.php).
  test('the job trigger answers that its job waits for approval', async ({ page }) => {
    await openJobTemplate(page, HOST_TEMPLATE);
    const token = await generateTriggerToken(page, '');
    let jobId = '';
    try {
      const response = await page.request.post('/trigger/fire', { headers: { [TRIGGER_HEADER]: token } });
      expect(response.status(), await response.text()).toBe(201);
      const body = (await response.json()) as { job_id?: number; status?: string };
      jobId = String(body.job_id ?? '');
      expect(body).toEqual({ job_id: Number(jobId), status: 'pending_approval' });

      await page.goto(`/job/view?id=${jobId}`);
      await expect(jobStatus(page)).toHaveText('Awaiting Approval');
      await linkedInventoryId(jobInventoryCell(page), HOST_INVENTORY);
      await page.goto(`/approval/view?id=${await requestOfJob(page, jobId)}`);
      await expect(requiredByCell(page)).toHaveText(REQUIRED_BY.inventory);
    } finally {
      await cancelJobIfActive(page, jobId);
      await openJobTemplate(page, HOST_TEMPLATE);
      await revokeTriggerTokenIfPresent(page);
    }
  });

  test('a re-launch of its job waits for approval, and the flash says so', async ({ page }) => {
    const jobId = await launchTemplate(page, HOST_TEMPLATE);
    let relaunchedId = '';
    try {
      await cancelJob(page);
      await expect(jobStatus(page)).toHaveText('Canceled');

      relaunchedId = await relaunchJob(page);
      expect(relaunchedId, 'a new job').not.toBe(jobId);
      await expect(flash(page, 'info')).toHaveText(relaunchedWaiting(relaunchedId));
      await expect(flash(page, 'success')).toHaveCount(0);
      await expect(jobStatus(page)).toHaveText('Awaiting Approval');
      await linkedInventoryId(jobInventoryCell(page), HOST_INVENTORY);
      await page.goto(`/approval/view?id=${await requestOfJob(page, relaunchedId)}`);
      await expect(requiredByCell(page)).toHaveText(REQUIRED_BY.inventory);
      await expect(page.locator('#approval-launched-by')).toHaveText('e2e-admin');
    } finally {
      await cancelJobIfActive(page, relaunchedId);
      await cancelJobIfActive(page, jobId);
    }
  });
});

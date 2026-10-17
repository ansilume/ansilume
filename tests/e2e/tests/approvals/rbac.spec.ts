import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { HOST_INVENTORY, HOST_RULE, HOST_TEMPLATE } from '../../lib/inventories';
import { cancelJobIfActive } from '../../lib/runs';
import { asAdmin, forgePost, indexRows, rowsWhere } from '../../lib/scoping';
import {
  GATED_TEMPLATE,
  LAUNCHER_REFUSED,
  REQUEST_RULE_COLUMN,
  REQUIRED_BY,
  cancelJob,
  decisionForms,
  decisionRows,
  expectClosedRequest,
  flash,
  launchTemplate,
  linkedInventoryId,
  requestInventoryCell,
  requestOfJob,
  requiredByCell,
  ruleOfInventory,
  waitingForApproval,
} from '../../lib/approvals';

/**
 * The viewer only sees the approval requests of jobs in their team's projects
 * and of workflows whose every job step is in one (commands/E2eWorkflowScopingSeeder.php:
 * the run of e2e-beta-approval-wf waits for a request on e2e-beta-approval-rule).
 * The rule lists the viewer as approver, but the viewer role lacks approval.decide.
 */
async function openBetaRequest(page: Page): Promise<string> {
  const rows = rowsWhere(await indexRows(page, '/approval/index'), REQUEST_RULE_COLUMN, 'e2e-beta-approval-rule');
  expect(rows, 'the viewer sees the request of their team\'s workflow').toHaveLength(1);
  await page.goto(`/approval/view?id=${rows[0].id}`);
  await expect(page.locator('#page-content table').first()).toContainText('Pending');
  return rows[0].id;
}

test.describe('Approvals RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view approvals index', async ({ page }) => {
    await page.goto('/approval/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(page.locator('#page-content table.table tbody')).toContainText('e2e-beta-approval-rule');
  });

  test('viewer cannot see approve or reject buttons', async ({ page }) => {
    // Regression: this used the first listed request and skipped itself when
    // the viewer saw none, which is every request of an e2e-template workflow
    // since team scoping covers workflows.
    await openBetaRequest(page);
    await expect(page.locator('form[action*="/approval/approve"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/approval/reject"]')).toHaveCount(0);
  });

  test('viewer gets 403 on a CSRF-valid POST /approval/approve', async ({ page }) => {
    const id = await openBetaRequest(page);

    // A real form post with a valid CSRF token: only the permission check may stop it.
    await forgePost(page, `/approval/approve?id=${id}`, {}, /\/approval\/approve/);
    await expectForbidden(page);

    await page.goto(`/approval/view?id=${id}`);
    await expect(page.locator('#page-content table').first()).toContainText('Pending');
  });

  // commands/E2eApprovalSeeder.php: e2e-gated-template is in e2e-project,
  // which e2e-operator's team operates; e2e-admin, the rule's other approver,
  // decides on what e2e-operator launches. Regression: the launcher could
  // approve their own job; this holds for operators as for admins.
  test('operator launches a gated template, and as its launcher cannot decide on it', async ({ page }) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    await expect(flash(page, 'info')).toHaveText(waitingForApproval(jobId));
    const requestId = await requestOfJob(page, jobId);

    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-launched-by')).toHaveText('e2e-operator');
    await expect(page.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED);
    await expect(decisionForms(page)).toHaveCount(0);
    await forgePost(page, `/approval/approve?id=${requestId}`, {}, /\/approval\/approve/);
    await expectForbidden(page);
    await expect(page.locator('#page-content')).toContainText(LAUNCHER_REFUSED);

    // Canceling the job closes its request.
    await page.goto(`/job/view?id=${jobId}`);
    await cancelJob(page);
    await expectClosedRequest(page, requestId, 'Canceled');
    await expect(decisionRows(page)).toHaveCount(0);
  });

  // commands/E2eInventoryGateSeeder.php: e2e-host-gated-template has no rule
  // of its own; its inventory, e2e-gated-inventory, has e2e-host-gate-rule,
  // on which e2e-admin and e2e-operator approve. Its project has no team, so
  // every role sees its jobs and requests. Whatever rule gates the job, the
  // user who launched it never decides on it.
  test('operator launches a job template on a gated inventory: the job waits, its request names the inventory, and its launcher cannot decide', async ({ page }) => {
    const jobId = await launchTemplate(page, HOST_TEMPLATE);
    try {
      await expect(flash(page, 'info')).toHaveText(waitingForApproval(jobId));
      const requestId = await requestOfJob(page, jobId);

      await page.goto(`/approval/view?id=${requestId}`);
      await expect(requiredByCell(page)).toHaveText(REQUIRED_BY.inventory);
      await linkedInventoryId(requestInventoryCell(page), HOST_INVENTORY);
      await expect(page.locator('#approval-launched-by')).toHaveText('e2e-operator');
      await expect(page.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED);
      await expect(decisionForms(page)).toHaveCount(0);
      await forgePost(page, `/approval/approve?id=${requestId}`, {}, /\/approval\/approve/);
      await expectForbidden(page);
      await expect(page.locator('#page-content')).toContainText(LAUNCHER_REFUSED);

      // Canceling the job closes its request.
      await page.goto(`/job/view?id=${jobId}`);
      await cancelJob(page);
      await expectClosedRequest(page, requestId, 'Canceled');
      await expect(decisionRows(page)).toHaveCount(0);
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  test('viewer sees that the inventory of a job required its request, and cannot decide on it', async ({ page, browser }, testInfo) => {
    const jobId = await asAdmin(browser, testInfo, (admin) => launchTemplate(admin, HOST_TEMPLATE));
    try {
      const requestId = await requestOfJob(page, jobId);
      await page.goto(`/approval/view?id=${requestId}`);
      await expect(requiredByCell(page)).toHaveText(REQUIRED_BY.inventory);
      await linkedInventoryId(requestInventoryCell(page), HOST_INVENTORY);
      await expect(page.locator('#approval-rule a')).toHaveText(HOST_RULE);
      await expect(decisionForms(page)).toHaveCount(0);

      // The approval list names the inventory in the Rule cell.
      await page.goto('/approval/index?status=pending');
      await expect(page.locator(`#approval-requests-table tr[data-request-id="${requestId}"] td[data-approval-rule]`))
        .toHaveText(ruleOfInventory(HOST_RULE, HOST_INVENTORY));

      // A real form post with a valid CSRF token: only the permission check may stop it.
      await forgePost(page, `/approval/approve?id=${requestId}`, {}, /\/approval\/approve/);
      await expectForbidden(page);
    } finally {
      await asAdmin(browser, testInfo, (admin) => cancelJobIfActive(admin, jobId));
    }
  });
});

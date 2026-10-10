import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { asAdmin, forgePost, indexRows, rowsWhere } from '../../lib/scoping';

/**
 * Team scoping of approval requests. A request of a workflow approval step
 * (its job is a placeholder without template) follows its workflow. Seeing a
 * request needs view access; deciding needs approval.decide, a place on the
 * rule's approver list and view access.
 *
 * Fixture (commands/E2eWorkflowScopingSeeder.php): the run of
 * e2e-beta-approval-wf waits at its approval step on e2e-beta-approval-rule,
 * whose approvers are e2e-viewer (sees it, may not decide) and e2e-operator
 * (may decide, but cannot see it: the workflow has a job step on e2e-beta-tmpl).
 */
const INDEX = '/approval/index';
const RULE = 'e2e-beta-approval-rule';
// Columns of the index: #, Job, Rule, Status, Requested, Resolved.
const RULE_COLUMN = 2;
const STATUS_COLUMN = 3;

/** The beta request and its placeholder job, looked up as e2e-admin. */
async function betaRequest(page: Page): Promise<{ id: string; jobId: string }> {
  const rows = rowsWhere(await indexRows(page, INDEX), RULE_COLUMN, RULE);
  expect(rows, `one request on ${RULE}`).toHaveLength(1);
  return { id: rows[0].id, jobId: rows[0].cells[1].replace('#', '') };
}

async function expectNoDecisionForms(page: Page) {
  await expect(page.locator('form[action*="/approval/approve"]')).toHaveCount(0);
  await expect(page.locator('form[action*="/approval/reject"]')).toHaveCount(0);
}

test.describe('Team Scoping — Approvals', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
    if (pn === 'admin' && !title.startsWith('admin')) test.skip();
  });

  // -- Operator (team-alpha) ---------------------------------------------------

  test('operator does not see the approval request of another team\'s workflow', async ({ page }) => {
    const rows = await indexRows(page, INDEX);
    expect(rowsWhere(rows, RULE_COLUMN, RULE)).toHaveLength(0);
  });

  test('operator cannot see or decide the request although the rule lists them as approver', async ({ page, browser }, testInfo) => {
    const request = await asAdmin(browser, testInfo, betaRequest);

    await page.goto(`/approval/view?id=${request.id}`);
    await expectForbidden(page);

    // Real form posts with a valid CSRF token: only the access check may stop them.
    await page.goto(INDEX);
    await forgePost(page, `/approval/approve?id=${request.id}`, { comment: 'forged' }, /\/approval\/approve/);
    await expectForbidden(page);
    await page.goto(INDEX);
    await forgePost(page, `/approval/reject?id=${request.id}`, {}, /\/approval\/reject/);
    await expectForbidden(page);

    await asAdmin(browser, testInfo, async (admin) => {
      const rows = rowsWhere(await indexRows(admin, INDEX), RULE_COLUMN, RULE);
      expect(rows[0].cells[STATUS_COLUMN], 'the request is still pending').toBe('Pending');
      await admin.goto(`/approval/view?id=${request.id}`);
      await expect(admin.locator('#page-content')).not.toContainText('Decisions');
    });
  });

  test('operator gets 403 on the placeholder job of another team\'s approval step', async ({ page, browser }, testInfo) => {
    // A job without template is not global: it follows its workflow.
    const request = await asAdmin(browser, testInfo, betaRequest);
    await page.goto(`/job/view?id=${request.jobId}`);
    await expectForbidden(page);
  });

  // -- Viewer (team-beta) --------------------------------------------------------

  test('viewer sees the pending approval request of their team\'s workflow', async ({ page }) => {
    const rows = rowsWhere(await indexRows(page, INDEX), RULE_COLUMN, RULE);
    expect(rows).toHaveLength(1);
    expect(rows[0].cells[STATUS_COLUMN]).toBe('Pending');
  });

  test('viewer on the approver list may see the request but not decide without approval.decide', async ({ page }) => {
    const request = await betaRequest(page);
    await page.goto(`/approval/view?id=${request.id}`);
    await expect(page.locator('#page-content')).toContainText(RULE);
    await expectNoDecisionForms(page);

    await forgePost(page, `/approval/approve?id=${request.id}`, {}, /\/approval\/approve/);
    await expectForbidden(page);

    await page.goto(`/approval/view?id=${request.id}`);
    await expect(page.locator('#page-content table').first()).toContainText('Pending');
  });

  test('viewer opens the placeholder job of their team\'s approval step', async ({ page }) => {
    const request = await betaRequest(page);
    await page.goto(`/approval/view?id=${request.id}`);
    await page.locator('#page-content table').first().getByRole('link', { name: `#${request.jobId}` }).click();
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(page.locator('h2').first()).toContainText(`Job #${request.jobId}`);
  });

  // -- Admin -------------------------------------------------------------------------

  test('admin sees the request but may not decide it: not on the approver list', async ({ page }) => {
    const request = await betaRequest(page);
    await page.goto(`/approval/view?id=${request.id}`);
    await expect(page.locator('#page-content')).toContainText(RULE);
    await expectNoDecisionForms(page);
  });
});

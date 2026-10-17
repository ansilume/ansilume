import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { cancelJobIfActive } from '../../lib/runs';
import { forgePost } from '../../lib/scoping';
import {
  APPROVAL_RULE,
  GATED_TEMPLATE,
  LAUNCHER_REFUSED,
  REQUIRED_BY,
  asOperator,
  cancelJob,
  decide,
  decisionForms,
  decisionRows,
  flash,
  launchTemplate,
  linkedInventoryId,
  requestInventoryCell,
  requestOfJob,
  requestStatus,
  requiredByCell,
  resolvedMessage,
  waitingForApproval,
} from '../../lib/approvals';

/**
 * Deciding on approval requests (views/approval/view.php). e2e-admin, the
 * user of this project, launches e2e-gated-template, which
 * e2e-approval-rule gates (commands/E2eApprovalSeeder.php). The launcher
 * never decides on their own job, so e2e-operator, the rule's other
 * approver, decides.
 */

/**
 * The inventory of e2e-template, which e2e-gated-template shares
 * (commands/E2eApprovalSeeder.php); it has no approval rule.
 */
const TEMPLATE_INVENTORY = 'e2e-inventory';

test.describe('Approval Decisions', () => {
  test('a launch of a gated template waits for approval and names its launcher, rule and inventory', async ({ page }) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    try {
      await expect(flash(page, 'info')).toHaveText(waitingForApproval(jobId));
      await expect(page.locator('#status-badge')).toHaveText('Awaiting Approval');

      const requestId = await requestOfJob(page, jobId);
      await page.goto(`/approval/view?id=${requestId}`);
      await expect(requestStatus(page)).toHaveText('Pending');
      await expect(page.locator('#approval-launched-by')).toHaveText('e2e-admin');
      const rule = page.locator('#approval-rule a');
      await expect(rule).toHaveText(APPROVAL_RULE);
      await expect(rule).toHaveAttribute('href', /\/approval-rule\/view\?id=\d+/);
      // The rule is the job template's own; its inventory has none.
      await expect(requiredByCell(page)).toHaveText(REQUIRED_BY.job_template);
      await linkedInventoryId(requestInventoryCell(page), TEMPLATE_INVENTORY);

      await page.goto(`/job/view?id=${jobId}`);
      await cancelJob(page);
    } finally {
      await cancelJobIfActive(page, jobId);
    }
  });

  // Regression: the job page's live status replaced "Awaiting Approval" with
  // the raw status ("Pending_approval") and a grey badge at its first poll.
  test('the job page keeps showing "Awaiting Approval" in its colour once its live status polled', async ({ page }) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    const badge = page.locator('#status-badge');
    await expect(badge).toHaveText('Awaiting Approval');
    await expect(badge).toHaveClass(/(^|\s)text-bg-info(\s|$)/);

    // Blank the badge: what it shows next, a poll of /job/log-poll wrote
    // (the page polls every few seconds while the job is not finished).
    await badge.evaluate((element) => {
      element.textContent = '';
      element.setAttribute('class', 'badge');
    });
    await expect(badge).toHaveText('Awaiting Approval', { timeout: 10_000 });
    await expect(badge).toHaveClass(/(^|\s)text-bg-info(\s|$)/);
    await expect(page.locator('#detail-status')).toHaveText('Awaiting Approval');

    await cancelJob(page);
    await expect(badge).toHaveText('Canceled');
  });

  // Regression: the launcher could approve their own job.
  test('the launcher sees why they cannot decide, and a forged vote of theirs is refused with 403', async ({ page }) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    const requestId = await requestOfJob(page, jobId);
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED);
    await expect(decisionForms(page)).toHaveCount(0);

    // Real form posts with a valid CSRF token: only the server's check may stop them.
    for (const decision of ['approve', 'reject']) {
      await page.goto(`/approval/view?id=${requestId}`);
      await forgePost(page, `/approval/${decision}?id=${requestId}`, { comment: 'self-approval' }, new RegExp(`/approval/${decision}`));
      await expectForbidden(page);
      await expect(page.locator('#page-content')).toContainText(LAUNCHER_REFUSED);
    }

    await page.goto(`/approval/view?id=${requestId}`);
    await expect(requestStatus(page)).toHaveText('Pending');
    await expect(decisionRows(page)).toHaveCount(0);
    await page.goto(`/job/view?id=${jobId}`);
    await cancelJob(page);
  });

  test('another approver approves with a comment, and the job is queued', async ({ page, browser }, testInfo) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    const requestId = await requestOfJob(page, jobId);
    const comment = `e2e approved ${Date.now()}`;

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await expect(operator.locator('#approval-decision-note')).toHaveCount(0);
      await expect(operator.locator('#approval-reject-comment')).toHaveAttribute('placeholder', 'Reason (optional)');
      await decide(operator, 'approve', comment);

      await expect(flash(operator, 'success')).toHaveText('Approval recorded.');
      await expect(requestStatus(operator)).toHaveText('Approved');
      await expect(decisionForms(operator)).toHaveCount(0);
      const row = decisionRows(operator);
      await expect(row).toHaveCount(1);
      await expect(row.locator('td').nth(0)).toHaveText('e2e-operator');
      await expect(row.locator('td').nth(1)).toContainText('approved');
      await expect(row.locator('td').nth(2)).toHaveText(comment);
      await expect(operator.locator('#page-content table').first().locator('tr', { hasText: 'Resolved' }).locator('td'))
        .toHaveText(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    });

    // Approved: the job runs now (a runner may already have picked it up).
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText(/Queued|Running|Succeeded|Failed/);
  });

  test('another approver rejects with a reason, which Decisions shows, and the job never runs', async ({ page, browser }, testInfo) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    const requestId = await requestOfJob(page, jobId);
    const reason = `e2e rejected ${Date.now()}`;

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await decide(operator, 'reject', reason);

      await expect(flash(operator, 'success')).toHaveText('Rejection recorded.');
      await expect(requestStatus(operator)).toHaveText('Rejected');
      await expect(decisionForms(operator)).toHaveCount(0);
      const row = decisionRows(operator);
      await expect(row).toHaveCount(1);
      await expect(row.locator('td').nth(0)).toHaveText('e2e-operator');
      await expect(row.locator('td').nth(1)).toContainText('rejected');
      await expect(row.locator('td').nth(2)).toHaveText(reason);
    });

    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Rejected');
  });

  // Regression: a stale vote overwrote a request someone had resolved first.
  test('a late vote from a second browser context is refused with a warning and records nothing', async ({ page, browser }, testInfo) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    const requestId = await requestOfJob(page, jobId);

    await asOperator(browser, testInfo, async (first) => {
      await asOperator(browser, testInfo, async (second) => {
        await first.goto(`/approval/view?id=${requestId}`);
        await second.goto(`/approval/view?id=${requestId}`);
        await expect(second.locator('#approval-reject-form')).toBeVisible();

        await decide(first, 'approve');
        await expect(flash(first, 'success')).toHaveText('Approval recorded.');

        // The second page still shows the forms of the pending request.
        await decide(second, 'reject', 'too late');
        await expect(flash(second, 'warning')).toHaveText(resolvedMessage(requestId, 'approved'));
        await expect(requestStatus(second)).toHaveText('Approved');
        await expect(decisionRows(second)).toHaveCount(1);
        await expect(decisionRows(second).locator('td').nth(1)).toContainText('approved');
        await expect(decisionForms(second)).toHaveCount(0);
        await expect(second.locator('#approval-decision-note')).toHaveText(resolvedMessage(requestId, 'approved'));
      });
    });
  });

  test('a comment that is too long or no text is refused with a danger flash and records nothing', async ({ page, browser }, testInfo) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    const requestId = await requestOfJob(page, jobId);
    const refusals: [Record<string, string>, string][] = [
      // The form's field allows 1000 characters; only a forged form sends more.
      [{ comment: 'x'.repeat(1001) }, 'Comment should contain at most 1,000 characters.'],
      [{ 'comment[]': 'x' }, 'Comment must be a string.'],
    ];

    await asOperator(browser, testInfo, async (operator) => {
      for (const [fields, error] of refusals) {
        await operator.goto('/approval/index');
        await forgePost(operator, `/approval/approve?id=${requestId}`, fields, /\/approval\/view\?id=\d+/);
        await expect(flash(operator, 'danger')).toHaveText(`Your decision was not recorded: The decision cannot be recorded: ${error}`);
        await expect(requestStatus(operator)).toHaveText('Pending');
        await expect(decisionRows(operator)).toHaveCount(0);
        await expect(operator.locator('#approval-approve-form')).toBeVisible();
      }
    });

    await page.goto(`/job/view?id=${jobId}`);
    await cancelJob(page);
  });
});

import { test, expect } from '@playwright/test';
import { forgePost } from '../../lib/scoping';
import { E2E_APPROVAL_LAUNCHER, asUser } from '../../lib/session';
import {
  ALREADY_VOTED,
  DUAL_RULE,
  DUAL_TEMPLATE,
  approvalsCount,
  asOperator,
  decide,
  decisionForms,
  decisionRows,
  flash,
  launchTemplate,
  requestOfJob,
  requestStatus,
  voteNotRecorded,
  waitingForApproval,
} from '../../lib/approvals';

/**
 * A request that needs more than one approval (views/approval/view.php).
 * e2e-dual-approval-rule needs the approvals of e2e-admin and e2e-operator;
 * e2e-approval-launcher, who is neither, launches e2e-dual-gated-template
 * (commands/E2eApprovalSeeder.php). An approver who voted sees the request
 * wait for the other approval and cannot vote again; the other approver
 * still decides.
 */
test.describe('Approval Requests That Need Two Approvals', () => {
  test('an approver who voted sees why they cannot vote again, and a forged second vote gets a danger flash', async ({ page, browser }, testInfo) => {
    const jobId = await asUser(browser, testInfo, E2E_APPROVAL_LAUNCHER, async (launcher) => {
      const launched = await launchTemplate(launcher, DUAL_TEMPLATE);
      await expect(flash(launcher, 'info')).toHaveText(waitingForApproval(launched));
      return launched;
    });
    const requestId = await requestOfJob(page, jobId);
    const comment = `e2e first of two ${Date.now()}`;

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await expect(operator.locator('#approval-launched-by')).toHaveText(E2E_APPROVAL_LAUNCHER.username);
      await expect(operator.locator('#approval-rule')).toHaveText(DUAL_RULE);
      await expect(approvalsCount(operator)).toHaveText('0 / 2');
      await decide(operator, 'approve', comment);
      await expect(flash(operator, 'success')).toHaveText('Approval recorded.');

      // Reloaded: one of two approvals, so the request still waits; the
      // operator's vote is in, so no forms, but the reason.
      await operator.reload();
      await expect(flash(operator, 'success')).toHaveCount(0);
      await expect(requestStatus(operator)).toHaveText('Pending');
      await expect(approvalsCount(operator)).toHaveText('1 / 2');
      await expect(decisionForms(operator)).toHaveCount(0);
      await expect(operator.locator('#approval-decision-note')).toHaveText(ALREADY_VOTED);

      // Real form posts with a valid CSRF token: only the server's check may stop them.
      for (const decision of ['approve', 'reject']) {
        await operator.goto('/approval/index');
        await forgePost(operator, `/approval/${decision}?id=${requestId}`, { comment: `second ${decision}` }, /\/approval\/view\?id=\d+/);
        await expect(flash(operator, 'danger')).toHaveText(voteNotRecorded(ALREADY_VOTED));
        await expect(requestStatus(operator)).toHaveText('Pending');
        await expect(approvalsCount(operator)).toHaveText('1 / 2');
        const votes = decisionRows(operator);
        await expect(votes).toHaveCount(1);
        await expect(votes.locator('td').nth(0)).toHaveText('e2e-operator');
        await expect(votes.locator('td').nth(1)).toContainText('approved');
        await expect(votes.locator('td').nth(2)).toHaveText(comment);
        await expect(decisionForms(operator)).toHaveCount(0);
      }
    });

    // The first vote does not stand in the way of the other approver: e2e-admin
    // rejects, which resolves the request, and the job never runs.
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-decision-note')).toHaveCount(0);
    await decide(page, 'reject', 'e2e: the second approver rejects');
    await expect(flash(page, 'success')).toHaveText('Rejection recorded.');
    await expect(requestStatus(page)).toHaveText('Rejected');
    await expect(decisionRows(page)).toHaveCount(2);
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Rejected');
  });
});

import { test, expect } from '@playwright/test';
import { forgePost } from '../../lib/scoping';
import {
  DELETED_GATE_TEMPLATE,
  decisionForms,
  decisionRows,
  deletedTemplateRequest,
  flash,
  jobNotWaiting,
  requestStatus,
  resolvedMessage,
} from '../../lib/approvals';
import { cancelJobIfActive } from '../../lib/runs';

/**
 * An approval request whose job's job template was deleted while the job
 * waited for approval (services/ApprovalJobState.php): the job no longer
 * waits, so nobody may decide on the request, and a vote closes the request
 * and cancels the job instead of approving it. Deleting a template cancels
 * its waiting jobs right after its commit, and the maintenance run cancels
 * any it left; this is the moment in between, or a cancel that failed.
 *
 * The YII_DEBUG hook /e2e/create-deleted-template-request makes the job of
 * the deleted e2e-deleted-gate-template that e2e-operator launched, gated by
 * e2e-approval-rule, and its pending request, right before each test
 * (commands/E2eDeletedTemplateApprovalSeeder.php). e2e-admin, the rule's
 * other approver, opens it. A job a test leaves waiting is canceled after it,
 * which closes its request.
 */
test.describe('Approval of a job of a deleted job template', () => {
  let waiting = '';

  test.afterEach(async ({ page }) => {
    await cancelJobIfActive(page, waiting);
    waiting = '';
  });

  test('the request page offers no vote and says why the job no longer waits', async ({ page, request, baseURL }) => {
    const { jobId, requestId } = await deletedTemplateRequest(request, baseURL, 'e2e-operator');
    waiting = jobId;

    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Awaiting Approval');
    await expect(page.locator('#page-content')).toContainText(`${DELETED_GATE_TEMPLATE} — launched by e2e-operator`);

    await page.goto(`/approval/view?id=${requestId}`);
    await expect(requestStatus(page)).toHaveText('Pending');
    await expect(page.locator('#approval-launched-by')).toHaveText('e2e-operator');
    await expect(page.locator('#approval-decision-note')).toHaveText(jobNotWaiting(requestId, jobId));
    await expect(decisionForms(page)).toHaveCount(0);

    // Only looking changes nothing: the request still waits, and so does the job.
    await page.reload();
    await expect(requestStatus(page)).toHaveText('Pending');
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Awaiting Approval');
  });

  // Regression: an approval of a waiting job of a deleted job template queued it.
  test('a forged approval gets the warning, closes the request and cancels the job', async ({ page, request, baseURL }) => {
    const { jobId, requestId } = await deletedTemplateRequest(request, baseURL, 'e2e-operator');
    const note = jobNotWaiting(requestId, jobId);

    // The page offers no Approve: a real form post with a valid CSRF token,
    // so only the server's check may stop it.
    await page.goto('/approval/index');
    await forgePost(page, `/approval/approve?id=${requestId}`, { comment: 'approves a job of a deleted template' }, new RegExp(`/approval/view\\?id=${requestId}$`));

    await expect(flash(page, 'warning')).toHaveText(note);
    await expect(flash(page, 'success')).toHaveCount(0);
    await expect(requestStatus(page)).toHaveText('Canceled');
    await expect(page.locator('#approval-decision-note')).toHaveText(resolvedMessage(requestId, 'canceled'));
    await expect(decisionForms(page)).toHaveCount(0);
    await expect(decisionRows(page)).toHaveCount(0);

    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Canceled');
    await expect(page.locator('#detail-status')).toHaveText('Canceled');
  });
});

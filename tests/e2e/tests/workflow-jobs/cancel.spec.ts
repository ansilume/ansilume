import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { flash, requestJobId, requestStatus } from '../../lib/approvals';
import {
  AWAITING_APPROVAL,
  CANCELED,
  STEP_RUN_CANCELED,
  cancelRunIfActive,
  launchedAwaitingApproval,
  stepBadge,
  stepRequestId,
} from '../../lib/runs';
import { forgePost } from '../../lib/scoping';
import { openWorkflowTemplate } from '../../lib/triggers';
import { CANCEL_APPROVE, CANCEL_JOB, CANCEL_WORKFLOW, GATE_RULE, runStepRow } from '../../lib/workflows';

/**
 * Canceling a run that waits for its approval: the run ends as canceled, its
 * approval step reads Canceled with why, and the step's approval request is
 * canceled with it, so nobody can decide it any more. A run that ended cannot
 * be canceled again.
 *
 * Fixture: e2e-cancel-workflow, an approval step on e2e-gate-rule, then a job
 * step (commands/E2eWorkflowGateSeeder.php). e2e-admin launches it;
 * e2e-operator, the rule's other approver, could decide. The viewer, who may
 * not cancel: workflow-jobs/rbac.spec.ts.
 */
test.describe('Workflow Job Cancel', () => {
  let runId = '';

  // The test cancels the run itself; this only catches a test that failed before.
  test.afterEach(async ({ page }) => {
    await cancelRunIfActive(page, runId);
    runId = '';
  });

  test('canceling a run that waits for its approval cancels the request with it', async ({ page }) => {
    await openWorkflowTemplate(page, CANCEL_WORKFLOW);
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForURL(/\/workflow-job\/view\?id=\d+$/),
      page.locator('#page-content form[action*="/workflow-template/launch"] button').click(),
    ]);
    runId = new URL(page.url()).searchParams.get('id') ?? '';
    // The launch flash names the step that waits and its request.
    const launchFlash = flash(page, 'success');
    await expect(launchFlash).toHaveText(launchedAwaitingApproval(CANCEL_WORKFLOW, CANCEL_APPROVE));
    const flashedRequest = ((await launchFlash.textContent()) ?? '').match(launchedAwaitingApproval(CANCEL_WORKFLOW, CANCEL_APPROVE))?.[1];

    const approve = runStepRow(page, CANCEL_APPROVE);
    await expect(approve.locator('[data-wjs-type-cell]')).toHaveText('Approval');
    await expect(stepBadge(approve)).toHaveText(AWAITING_APPROVAL);
    const requestId = await stepRequestId(approve);
    expect(flashedRequest, 'the flash names the request the step waits for').toBe(requestId);
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-rule')).toHaveText(GATE_RULE);
    await expect(requestStatus(page)).toHaveText('Pending');
    const placeholder = await requestJobId(page);

    await page.goto(`/workflow-job/view?id=${runId}`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('form[action*="/workflow-job/cancel"] button[type="submit"]').click();
    await expectFlash(page, 'success', 'Workflow canceled.');
    await expect(page.locator('#wj-status')).toHaveText(CANCELED);
    await expect(page.locator('#wj-live')).toHaveCount(0);
    await expect(page.locator('form[action*="/workflow-job/cancel"]')).toHaveCount(0);
    // The run cancel ended the step: it reads Canceled, not Failed.
    await expect(stepBadge(approve)).toHaveText(CANCELED);
    await expect(approve.locator('[data-wjs-error]')).toHaveText(STEP_RUN_CANCELED);
    await expect(runStepRow(page, CANCEL_JOB), 'the job step never started').toHaveCount(0);

    // Regression: canceling a run left its approval request pending, and
    // a decision on it later moved the canceled run on.
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(requestStatus(page)).toHaveText('Canceled');
    await expect(page.locator('#approval-approve-form')).toHaveCount(0);
    await expect(page.locator('#approval-reject-form')).toHaveCount(0);
    await page.goto(`/job/view?id=${placeholder}`);
    await expect(page.locator('#status-badge')).toHaveText('Canceled');

    // A real form post with a valid CSRF token: the run has ended.
    await page.goto(`/workflow-job/index`);
    await forgePost(page, `/workflow-job/cancel?id=${runId}`, {}, /\/workflow-job\/view/);
    await expectFlash(page, 'danger', 'Workflow is already finished.');
    await expect(page.locator('#wj-status')).toHaveText(CANCELED);
  });
});

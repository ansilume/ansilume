import { test, expect } from '@playwright/test';

/**
 * Regression tests for issue #14: workflow approval steps must create
 * ApprovalRequest records and advance the workflow when approved/rejected.
 *
 * Uses the e2e-approval-workflow template (approval step -> job step).
 * The approval rule uses APPROVER_TYPE_USERS with only e2e-admin.
 */
test.describe('Workflow Approval Flow', () => {
  const workflowName = 'e2e-approval-workflow';

  /**
   * Launch the approval workflow and return the workflow job URL.
   * Handles the launch confirmation dialog automatically.
   */
  async function launchWorkflow(page: import('@playwright/test').Page): Promise<string | null> {
    await page.goto('/workflow-template/index');
    const row = page.locator('table.table tbody tr', { hasText: workflowName });
    if (!(await row.isVisible({ timeout: 3_000 }).catch(() => false))) {
      return null;
    }

    await row.locator('a').first().click();
    await expect(page).toHaveURL(/workflow-template\/view/);

    // Handle launch confirmation dialog
    page.once('dialog', (dialog) => dialog.accept());
    const launchBtn = page.locator('button:has-text("Launch")').first();
    await expect(launchBtn).toBeVisible();
    await launchBtn.click();

    await expect(page).toHaveURL(/workflow-job\/view/, { timeout: 10_000 });
    return page.url();
  }

  test('launch approval workflow creates pending approval request', async ({ page }) => {
    const wfUrl = await launchWorkflow(page);
    if (wfUrl === null) {
      test.skip(true, 'e2e-approval-workflow not seeded');
      return;
    }

    // Verify the approval step is running
    const stepsTable = page.locator('table.table-bordered').last();
    await expect(stepsTable).toContainText('e2e-step-approve');
    await expect(stepsTable).toContainText('Running');

    // Navigate to approvals -- a pending request should exist
    await page.goto('/approval/index');
    const approvalRow = page.locator('table.table tbody tr', { hasText: 'Pending' });
    await expect(approvalRow.first()).toBeVisible({ timeout: 5_000 });
  });

  test('approving request advances workflow to next step', async ({ page }) => {
    const wfUrl = await launchWorkflow(page);
    if (wfUrl === null) {
      test.skip(true, 'e2e-approval-workflow not seeded');
      return;
    }

    // Go to approvals and find the pending request
    await page.goto('/approval/index');
    const pendingRow = page.locator('table.table tbody tr', { hasText: 'Pending' });
    await expect(pendingRow.first()).toBeVisible({ timeout: 5_000 });

    // Click into the approval detail
    await pendingRow.first().locator('a').first().click();
    await expect(page).toHaveURL(/approval\/view/);

    // Approve the request (handle confirm dialog)
    page.once('dialog', (dialog) => dialog.accept());
    const approveBtn = page.locator('button:has-text("Approve")');
    await expect(approveBtn).toBeVisible();
    await approveBtn.click();

    // Should see success flash
    await expect(page.locator('.alert-success')).toBeVisible({ timeout: 5_000 });
    // Status should change to Approved (in the first detail table)
    const statusCell = page.locator('table.table-bordered').first().locator('tr', { hasText: 'Status' });
    await expect(statusCell).toContainText('Approved');

    // Go back to the workflow job and verify advancement
    await page.goto(wfUrl);
    const stepsTable = page.locator('table.table-bordered').last();

    // Approval step should be Succeeded
    const approvalStepRow = stepsTable.locator('tr', { hasText: 'e2e-step-approve' });
    await expect(approvalStepRow).toContainText('Succeeded');

    // Job step should exist (Running or another status depending on runner availability)
    const jobStepRow = stepsTable.locator('tr', { hasText: 'e2e-step-job-after-approval' });
    await expect(jobStepRow).toBeVisible();
  });

  test('rejecting request fails workflow', async ({ page }) => {
    const wfUrl = await launchWorkflow(page);
    if (wfUrl === null) {
      test.skip(true, 'e2e-approval-workflow not seeded');
      return;
    }

    // Go to approvals and reject the pending request
    await page.goto('/approval/index');
    const pendingRow = page.locator('table.table tbody tr', { hasText: 'Pending' });
    await expect(pendingRow.first()).toBeVisible({ timeout: 5_000 });

    await pendingRow.first().locator('a').first().click();
    await expect(page).toHaveURL(/approval\/view/);

    // Reject the request (handle confirm dialog)
    page.once('dialog', (dialog) => dialog.accept());
    const rejectBtn = page.locator('button:has-text("Reject")');
    await expect(rejectBtn).toBeVisible();
    await rejectBtn.click();

    // Should see success flash (rejection recorded)
    await expect(page.locator('.alert-success')).toBeVisible({ timeout: 5_000 });
    // Status should change to Rejected (in the first detail table)
    const statusCell = page.locator('table.table-bordered').first().locator('tr', { hasText: 'Status' });
    await expect(statusCell).toContainText('Rejected');

    // Go back to workflow job -- it should be failed
    await page.goto(wfUrl);
    const statusTable = page.locator('table.table-bordered').first();
    await expect(statusTable).toContainText('Failed');

    // Approval step should be Failed
    const stepsTable = page.locator('table.table-bordered').last();
    const approvalStepRow = stepsTable.locator('tr', { hasText: 'e2e-step-approve' });
    await expect(approvalStepRow).toContainText('Failed');
  });
});

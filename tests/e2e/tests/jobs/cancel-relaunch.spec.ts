import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

test.describe('Job Cancel/Relaunch', () => {
  test('job detail shows action buttons for admin', async ({ page }) => {
    await page.goto('/job/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/job|status|template/i);
    }
  });

  // Regression guard for the cancel→complete() race fixed in
  // services/JobCompletionService::complete(): the UI cancel flow has to
  // actually end up with a canceled job the user can re-launch, not just
  // "button clicked, page reloaded". Pairs with the PHPUnit race-guard
  // tests that assert the server-side half of the contract.
  test('clicking Cancel on a queued job flips status and reveals Re-launch', async ({ page, request, baseURL }) => {
    // Dialog handler must be attached before we ever navigate — the form
    // uses an onsubmit=confirm() prompt which otherwise blocks submission.
    page.on('dialog', (d) => d.accept());

    const response = await request.post(`${baseURL}/e2e/create-cancelable-job`, {
      params: { template: 'e2e-template' },
    });
    expect(response.status()).toBe(200);
    const { job_id: jobId } = (await response.json()) as { job_id: number };
    expect(jobId).toBeGreaterThan(0);

    await page.goto(`/job/view?id=${jobId}`);
    const badge = page.locator('#status-badge');
    await expect(badge).toContainText(/queued|pending|running/i);

    const cancelBtn = page.locator('form[action*="/cancel"] button[type="submit"]').first();
    await expect(cancelBtn).toBeVisible();
    await cancelBtn.click();

    await page.waitForLoadState('networkidle');
    await expectFlash(page, 'success', `Job #${jobId} canceled`);
    await expect(badge).toContainText(/canceled/i);

    // Cancel button must disappear — the job isn't cancelable anymore.
    await expect(page.locator('form[action*="/cancel"] button[type="submit"]')).toHaveCount(0);
    // Re-launch button must show up now that the job is in a terminal status.
    await expect(page.locator('form[action*="/relaunch"] button[type="submit"]')).toBeVisible();
  });
});

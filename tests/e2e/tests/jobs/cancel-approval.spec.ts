import { test, expect, Page } from '@playwright/test';
import { postWithPageCsrf } from '../../lib/helpers';
import {
  GATED_TEMPLATE,
  asOperator,
  auditUsersOf,
  cancelJob,
  expectClosedRequest,
  flash,
  launchTemplate,
  requestOfJob,
  resolvedMessage,
} from '../../lib/approvals';

/**
 * Canceling a job that waits for approval closes its approval request
 * (services/JobCancellationService.php). e2e-admin launches
 * e2e-gated-template (commands/E2eApprovalSeeder.php); e2e-operator approves
 * on its rule and may cancel its jobs.
 */

/** How many pairs of crossing cancels the race test sends, each on a job of its own. */
const CROSSING_ATTEMPTS = 3;

/** The flash a cancel left on the job page, as the user of `page` sees it. */
async function cancelFlash(page: Page, jobId: string): Promise<{ type: string; text: string }> {
  await page.goto(`/job/view?id=${jobId}`);
  const alert = page.locator('#page-content > .alert[role="alert"]');
  await expect(alert).toHaveCount(1);
  const type = ((await alert.getAttribute('class')) ?? '').match(/alert-(success|warning|danger|info)/)?.[1] ?? '';
  return { type, text: ((await alert.textContent()) ?? '').trim() };
}

test.describe('Cancel a Job That Waits for Approval', () => {
  // Regression: canceling a job left its approval request pending and decidable.
  test('canceling closes its approval request: Canceled, and nobody can decide any more', async ({ page, browser }, testInfo) => {
    const jobId = await launchTemplate(page, GATED_TEMPLATE);
    const requestId = await requestOfJob(page, jobId);

    await page.goto(`/job/view?id=${jobId}`);
    await cancelJob(page);
    await expect(flash(page, 'success')).toHaveText(`Job #${jobId} canceled.`);
    await expect(page.locator('#status-badge')).toHaveText('Canceled');
    await expect(page.locator('form[action*="/job/cancel"]')).toHaveCount(0);

    await expectClosedRequest(page, requestId, 'Canceled');
    await asOperator(browser, testInfo, async (operator) => {
      // The approver who could have decided is told why they no longer can.
      await expectClosedRequest(operator, requestId, 'Canceled');
      await expect(operator.locator('#approval-decision-note')).toHaveText(resolvedMessage(requestId, 'canceled'));
    });
  });

  // Two users cancel the same job at the same moment. Every attempt ends in
  // one of two valid ways, depending on timing alone:
  // - both requests passed the controller's check before either cancel
  //   committed, and the cancel that lost changed nothing and says so
  //   (JobCancellationService::NOT_CANCELED);
  // - the later request came after the other cancel committed, and the
  //   controller's check refused it.
  // Either way exactly one cancel succeeds, and the job and its approval
  // request are canceled once. The test does not insist on the race: a
  // browser cannot force it. tests/integration/controllers/JobControllerCancelTest.php
  // (testAJobThatFinishedAfterTheActionLoadedItIsNotCanceled) pins the lost race.
  test('of two cancels that cross, one cancels, the other shows a warning, and the job is canceled once', async ({ page, browser }, testInfo) => {
    test.slow();
    await asOperator(browser, testInfo, async (operator) => {
      for (let attempt = 1; attempt <= CROSSING_ATTEMPTS; attempt++) {
        const jobId = await launchTemplate(page, GATED_TEMPLATE);
        await operator.goto(`/job/view?id=${jobId}`);
        const responses = await Promise.all([
          postWithPageCsrf(page, `/job/cancel?id=${jobId}`),
          postWithPageCsrf(operator, `/job/cancel?id=${jobId}`),
        ]);
        expect(responses.map((response) => response.status()), 'both redirect to the job page').toEqual([302, 302]);

        const flashes = [
          { user: 'e2e-admin', ...await cancelFlash(page, jobId) },
          { user: 'e2e-operator', ...await cancelFlash(operator, jobId) },
        ];
        const won = flashes.filter((shown) => shown.type === 'success');
        const lost = flashes.filter((shown) => shown.type !== 'success');
        expect(won.map((shown) => shown.text), 'exactly one cancel succeeds').toEqual([`Job #${jobId} canceled.`]);
        expect(lost).toHaveLength(1);
        expect(lost[0].type, 'the other is a warning, not an error').toBe('warning');
        const lostTheRace = `Job #${jobId} was not canceled: it is already canceled.`;
        const refusedByTheCheck = `Job #${jobId} cannot be canceled in status "canceled".`;
        expect([lostTheRace, refusedByTheCheck]).toContain(lost[0].text);
        testInfo.annotations.push({
          type: 'crossing cancels',
          description: `attempt ${attempt}, job #${jobId}: ${lost[0].text === lostTheRace
            ? 'both passed the check; the later cancel changed nothing'
            : 'the later request was refused by the check'}`,
        });

        await expect(page.locator('#status-badge')).toHaveText('Canceled');
        const requestId = await requestOfJob(page, jobId);
        await expectClosedRequest(page, requestId, 'Canceled');
        // Canceled once: one job.canceled and one approval.canceled entry, both by whoever succeeded.
        expect(await auditUsersOf(page, 'job.canceled', 'job', jobId), 'one job.canceled entry').toEqual([won[0].user]);
        expect(await auditUsersOf(page, 'approval.canceled', 'approval_request', requestId), 'one approval.canceled entry')
          .toEqual([won[0].user]);
      }
    });
  });
});

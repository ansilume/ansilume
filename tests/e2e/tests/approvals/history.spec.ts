import { test, expect, Page } from '@playwright/test';
import { templateId } from '../../lib/helpers';
import { IndexRow, forgePost, indexRows, rowsWhere } from '../../lib/scoping';
import {
  HISTORY_RULE,
  HISTORY_TEMPLATE,
  REQUEST_JOB_COLUMN,
  REQUEST_RULE_COLUMN,
  REQUEST_STATUS_COLUMN,
  approvalsCount,
  asOperator,
  decisionForms,
  decisionRows,
  expiredMessage,
  flash,
  requestStatus,
  resolvedMessage,
} from '../../lib/approvals';

/**
 * Requests that can no longer be decided (views/approval/view.php), from the
 * fixtures of e2e-history-gated-template (commands/E2eApprovalSeeder.php).
 * e2e-operator, who approves on e2e-history-approval-rule, looks at them: an
 * approver who may decide is told why the buttons are missing.
 */

/** The requests of the jobs of e2e-history-gated-template, from the approval list. */
async function historyRequests(page: Page): Promise<IndexRow[]> {
  const template = await templateId(page, HISTORY_TEMPLATE);
  const jobs = (await indexRows(page, `/job/index?template_id=${template}`)).map((row) => `#${row.id}`);
  expect(jobs, 'the seeded jobs of the template').toHaveLength(3);
  return (await indexRows(page, '/approval/index')).filter((row) => jobs.includes(row.cells[REQUEST_JOB_COLUMN]));
}

test.describe('Approval Requests That Cannot Be Decided', () => {
  test('an expired request offers no Approve or Reject, and a vote on it is refused with a warning', async ({ page, browser }, testInfo) => {
    // Expired two hours ago; the next maintenance run times it out, so it
    // may be pending or timed out by now.
    const waiting = (await historyRequests(page)).filter((row) => ['Pending', 'Timed Out'].includes(row.cells[REQUEST_STATUS_COLUMN]));
    expect(waiting, 'the request whose job waited').toHaveLength(1);
    expect(waiting[0].cells[REQUEST_RULE_COLUMN]).toBe(HISTORY_RULE);
    const requestId = waiting[0].id;
    // A flash carries the whitespace of its markup around the message.
    const either = new RegExp(`^\\s*(${escape(expiredMessage(requestId))}|${escape(resolvedMessage(requestId, 'timed out'))})\\s*$`);

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await expect(decisionForms(operator)).toHaveCount(0);
      const status = ((await requestStatus(operator).textContent()) ?? '').trim();
      expect(['Pending', 'Timed Out']).toContain(status);
      await expect(operator.locator('#approval-decision-note'))
        .toHaveText(status === 'Pending' ? expiredMessage(requestId) : resolvedMessage(requestId, 'timed out'));

      // A real form post with a valid CSRF token: the server refuses it as well.
      await operator.goto('/approval/index');
      await forgePost(operator, `/approval/approve?id=${requestId}`, { comment: 'too late' }, /\/approval\/view\?id=\d+/);
      await expect(flash(operator, 'warning')).toHaveText(either);
      await expect(requestStatus(operator)).toHaveText(/^(Pending|Timed Out)$/);
      await expect(decisionRows(operator)).toHaveCount(0);
    });
  });

  test('a request whose job was canceled shows Canceled and offers no Approve or Reject', async ({ page, browser }, testInfo) => {
    const canceled = rowsWhere(await historyRequests(page), REQUEST_STATUS_COLUMN, 'Canceled');
    expect(canceled).toHaveLength(1);
    const requestId = canceled[0].id;

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await expect(requestStatus(operator)).toHaveText('Canceled');
      await expect(decisionForms(operator)).toHaveCount(0);
      await expect(operator.locator('#approval-decision-note')).toHaveText(resolvedMessage(requestId, 'canceled'));

      await operator.goto('/approval/index');
      await forgePost(operator, `/approval/reject?id=${requestId}`, {}, /\/approval\/view\?id=\d+/);
      await expect(flash(operator, 'warning')).toHaveText(resolvedMessage(requestId, 'canceled'));
      await expect(requestStatus(operator)).toHaveText('Canceled');
      await expect(decisionRows(operator)).toHaveCount(0);
    });
  });

  test('a request whose rule was deleted shows "deleted rule" without a link', async ({ page }) => {
    const approved = rowsWhere(await historyRequests(page), REQUEST_STATUS_COLUMN, 'Approved');
    expect(approved).toHaveLength(1);
    expect(approved[0].cells[REQUEST_RULE_COLUMN], 'the list shows no rule').toBe('—');

    await page.goto(`/approval/view?id=${approved[0].id}`);
    await expect(requestStatus(page)).toHaveText('Approved');
    await expect(page.locator('#approval-rule')).toHaveText('deleted rule');
    await expect(page.locator('#approval-rule a')).toHaveCount(0);
    await expect(decisionForms(page)).toHaveCount(0);
  });

  test('a vote the launcher cast before self-approval was refused is marked and not counted', async ({ page }) => {
    // e2e-admin launched the job and voted, then e2e-operator approved.
    const approved = rowsWhere(await historyRequests(page), REQUEST_STATUS_COLUMN, 'Approved');
    expect(approved).toHaveLength(1);
    await page.goto(`/approval/view?id=${approved[0].id}`);

    const votes = decisionRows(page);
    await expect(votes).toHaveCount(2);
    await expect(votes.nth(0).locator('td').nth(0)).toHaveText('e2e-admin');
    await expect(votes.nth(0).locator('.approval-launcher-decision')).toHaveText('launcher, not counted');
    await expect(votes.nth(1).locator('td').nth(0)).toHaveText('e2e-operator');
    await expect(votes.nth(1).locator('.approval-launcher-decision')).toHaveCount(0);
    // Only e2e-operator's approval counts; the deleted rule's threshold is unknown.
    await expect(approvalsCount(page)).toHaveText('1 / ?');
  });
});

/** `text` as a literal in a regular expression. */
function escape(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

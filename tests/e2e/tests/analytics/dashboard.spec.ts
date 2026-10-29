import { test, expect, Page } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';
import { GATED_TEMPLATE, cancelJob, launchTemplate } from '../../lib/approvals';

/** services/ApprovalAnalytics.php AVG_DECISION_NOTE */
const AVG_DECISION_NOTE = 'Canceled requests are not counted.';

/** Opens the Approvals tab of the analytics page; the count of its Canceled card. */
async function canceledApprovals(page: Page): Promise<number> {
  await page.goto('/analytics/index');
  await page.locator('a[href="#tab-approvals"]').click();
  const canceled = page.locator('#analytics-approvals-canceled');
  await expect(canceled).toBeVisible();
  await expect(canceled).toHaveText(/^\d+$/);
  return Number(await canceled.textContent());
}

test.describe('Analytics Dashboard', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/analytics|dashboard/i);
  });

  test('shows charts or data sections', async ({ page }) => {
    await page.goto('/analytics/index');
    // Analytics page should have some content
    await expect(page.locator('body')).toContainText(/analytics|job|chart|stat|success|fail/i);
  });

  // The filter's date range: the last 30 days by default (AnalyticsQuery::applyDefaults()).
  // It counted any such input and accepted none.
  test('date filter present', async ({ page }) => {
    await page.goto('/analytics/index');
    for (const name of ['date_from', 'date_to']) {
      const input = page.locator(`#page-content input[type="date"][name="${name}"]`);
      await expect(input, name).toBeVisible();
      await expect(input, name).toHaveValue(/^\d{4}-\d{2}-\d{2}$/);
    }
  });

  test('shows workflows, approvals, runners tabs', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('a[href="#tab-workflows"]')).toContainText(/workflows/i);
    await expect(page.locator('a[href="#tab-approvals"]')).toContainText(/approvals/i);
    await expect(page.locator('a[href="#tab-runners"]')).toContainText(/runners/i);
  });

  test('workflows tab activates on click', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-workflows"]').click();
    await expect(page.locator('#tab-workflows')).toBeVisible();
  });

  test('approvals tab activates on click', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-approvals"]').click();
    await expect(page.locator('#tab-approvals')).toBeVisible();
  });

  test('runners tab activates on click', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-runners"]').click();
    await expect(page.locator('#tab-runners')).toBeVisible();
  });

  test('approvals tab counts canceled requests on a card of their own, left out of the average decision time', async ({ page }) => {
    const before = await canceledApprovals(page);
    // commands/E2eApprovalSeeder.php seeds a request canceled with its job.
    expect(before, 'the seeded canceled request').toBeGreaterThanOrEqual(1);
    const card = page.locator('#tab-approvals .card', { has: page.locator('#analytics-approvals-canceled') });
    await expect(card.locator('.text-muted').first()).toHaveText('Canceled');
    await expect(page.locator('#analytics-approvals-avg-decision-note')).toHaveText(AVG_DECISION_NOTE);
    await expect(page.locator('#analytics-approvals-avg-decision')).toHaveText(/^\d+(\.\d+)?s$/);

    // A job canceled while it waited for approval cancels its request too.
    await launchTemplate(page, GATED_TEMPLATE);
    await cancelJob(page);
    expect(await canceledApprovals(page)).toBeGreaterThanOrEqual(before + 1);
  });

  // Regression: a date_from of only whitespace (?date_from=%20) showed the
  // danger alert "The format of Date From is invalid." instead of the
  // reports, and a NUL byte (?date_from=%00) answered 500, a ValueError of the
  // date rule. Both are trimmed to no date now (models/AnalyticsQuery.php).
  //
  // The success and approval rates of the reports round the exact share
  // (app\components\Percent: 23 of 160 is 14.38, not 14.37 as part / whole *
  // 100 rounds since PHP 8.4). tests/integration/services/AnalyticsRatesTest.php
  // covers that for every report: here the page would need exactly 23
  // succeeded of 160 jobs in its range, which the jobs of the other specs
  // change.
  for (const [what, value] of [['a space', '%20'], ['a NUL byte', '%00']]) {
    test(`a date_from of ${what} shows the reports without an error`, async ({ page }) => {
      const answer = await page.goto(`/analytics/index?date_from=${value}`);

      expect(answer?.status(), 'the analytics page').toBe(200);
      await expect(page.locator('#page-content .alert-danger'), 'no filter error').toHaveCount(0);
      await expect(page.locator('#page-content')).toContainText('Total Jobs');
      await expect(page.locator('#page-content input[name="date_from"]')).toHaveAttribute('value', '');
    });
  }
});

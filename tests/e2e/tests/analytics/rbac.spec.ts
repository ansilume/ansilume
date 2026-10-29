import { test, expect } from '@playwright/test';
import { APPROVAL_SUMMARY_EXPORT, APPROVAL_SUMMARY_HEADER, CSV_CONTENT_TYPE, approvalsExportButton } from '../../lib/analytics';

test.describe('Analytics RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view analytics', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator can view analytics', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  // Regression: a date_from of a NUL byte answered 500 for every role that
  // may see analytics, and one of only whitespace showed the filter error
  // instead of the reports (models/AnalyticsQuery.php).
  test('viewer sees the reports for a date_from of a NUL byte or only whitespace, without an error', async ({ page }) => {
    for (const value of ['%00', '%20']) {
      const answer = await page.goto(`/analytics/index?date_from=${value}`);
      expect(answer?.status(), value).toBe(200);
      await expect(page.locator('#page-content .alert-danger'), value).toHaveCount(0);
      await expect(page.locator('#page-content')).toContainText('Total Jobs');
    }
  });

  // commands/E2eApprovalSeeder.php: a request canceled with its job, of
  // e2e-history-gated-template in e2e-project, which e2e-operator's team operates.
  test('operator sees canceled approval requests counted on a card of their own', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-approvals"]').click();
    const canceled = page.locator('#analytics-approvals-canceled');
    await expect(canceled).toHaveText(/^\d+$/);
    expect(Number(await canceled.textContent()), 'the seeded canceled request').toBeGreaterThanOrEqual(1);
    await expect(page.locator('#analytics-approvals-avg-decision-note')).toHaveText('Canceled requests are not counted.');
  });

  test('viewer sees the canceled card of the approvals tab', async ({ page }) => {
    await page.goto('/analytics/index');
    await page.locator('a[href="#tab-approvals"]').click();
    await expect(page.locator('#analytics-approvals-canceled')).toHaveText(/^\d+$/);
  });

  // analytics.export: operator and admin (migrations/m000049_000000_seed_analytics_rbac.php).
  // Regression: on PHP 8.4 and later the CSV export answered 500 for every
  // role that may export (app\components\CsvText).
  test('operator exports the approvals tab as CSV', async ({ page }) => {
    const button = await approvalsExportButton(page);
    await expect(button).toBeVisible();

    const answer = await page.request.get((await button.getAttribute('href')) ?? '');

    expect(answer.status()).toBe(200);
    expect(answer.headers()['content-type']).toBe(CSV_CONTENT_TYPE);
    expect((await answer.text()).split('\n')[0]).toBe(APPROVAL_SUMMARY_HEADER);
  });

  test('viewer has no Export CSV button and is refused the CSV export', async ({ page }) => {
    const button = await approvalsExportButton(page);
    await expect(button).toHaveCount(0);

    const answer = await page.request.get(APPROVAL_SUMMARY_EXPORT);

    expect(answer.status()).toBe(403);
    expect(answer.headers()['content-type']).not.toContain('text/csv');
  });
});

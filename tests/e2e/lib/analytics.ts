import { Locator, Page } from '@playwright/test';

/** The Content-Type of a CSV export (controllers/AnalyticsController.php, controllers/api/v1/AnalyticsController.php). */
export const CSV_CONTENT_TYPE = 'text/csv; charset=UTF-8';

/**
 * The header row of the approval summary export, the keys of
 * services/ApprovalAnalytics.php summary(). The report is one row whatever
 * the data, so its export always has the header row and one row.
 */
export const APPROVAL_SUMMARY_HEADER = 'total,approved,rejected,timed_out,canceled,pending,approval_rate,avg_decision_seconds';

/** The one row of the approval summary export: counts, then the rate and the average decision time. */
export const APPROVAL_SUMMARY_ROW = /^\d+,\d+,\d+,\d+,\d+,\d+,\d+(\.\d+)?,\d+(\.\d+)?$/;

/** The address of the approval summary's CSV export, as its Export CSV button starts it. */
export const APPROVAL_SUMMARY_EXPORT = '/analytics/export?report=approval-summary&format=csv';

/** Opens the Approvals tab of the analytics page; its Export CSV button. */
export async function approvalsExportButton(page: Page): Promise<Locator> {
  await page.goto('/analytics/index');
  await page.locator('a[href="#tab-approvals"]').click();
  await page.locator('#tab-approvals').waitFor({ state: 'visible' });
  return page.locator('#tab-approvals').getByRole('link', { name: 'Export CSV' });
}

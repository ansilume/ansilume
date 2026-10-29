import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import {
  APPROVAL_SUMMARY_EXPORT,
  APPROVAL_SUMMARY_HEADER,
  APPROVAL_SUMMARY_ROW,
  CSV_CONTENT_TYPE,
  approvalsExportButton,
} from '../../lib/analytics';
import { generateApiToken, revokeApiToken } from '../../lib/tokens';

/** A valid date range without any job: the job trend report has no row there. */
const EMPTY_RANGE = 'date_from=2001-01-01&date_to=2001-01-02';

test.describe('Analytics Export', () => {
  // analytics.export: the Export CSV link of the open Job Trend tab and that
  // of the Approvals tab. It counted export links and accepted none.
  test('export buttons are visible for admin', async ({ page }) => {
    await page.goto('/analytics/index');
    const trend = page.locator('#tab-trend').getByRole('link', { name: 'Export CSV' });
    await expect(trend).toBeVisible();
    await expect(trend).toHaveAttribute('href', /^\/analytics\/export\?report=job-trend&format=csv&/);
    await expect(await approvalsExportButton(page)).toBeVisible();
  });

  // Regression: the refusal had the flash type "error", which rendered as the
  // unstyled class alert-error instead of a red alert
  // (controllers/AnalyticsController.php actionExport).
  test('an export with invalid filters returns to the dashboard with a danger flash', async ({ page }) => {
    await page.goto('/analytics/export?date_from=not-a-date&report=summary&format=json');

    await expect(page).toHaveURL(/\/analytics\/index$/);
    await expect(page.locator('#page-content > .alert-danger[role="alert"]')).toHaveText('Invalid filter parameters.');
    await expect(page.locator('#page-content > .alert-error')).toHaveCount(0);
  });

  // Regression: on PHP 8.4 and later every CSV export with a row answered 500.
  // fputcsv() without its escape argument is deprecated there, and the error
  // handler turns the deprecation into an exception (app\components\CsvText).
  test('the Export CSV button of the approvals tab downloads the report as CSV', async ({ page }) => {
    const button = await approvalsExportButton(page);
    await expect(button).toBeVisible();
    const href = (await button.getAttribute('href')) ?? '';
    expect(href).toContain(APPROVAL_SUMMARY_EXPORT);

    const answer = await page.request.get(href);
    expect(answer.status(), 'the answer to the button\'s link').toBe(200);
    expect(answer.headers()['content-type']).toBe(CSV_CONTENT_TYPE);
    expect(answer.headers()['content-disposition']).toMatch(/^attachment; filename="analytics-approval-summary-\d{4}-\d{2}-\d{2}\.csv"$/);
    expect((await answer.text()).split('\n')[0]).toBe(APPROVAL_SUMMARY_HEADER);

    const [download] = await Promise.all([page.waitForEvent('download'), button.click()]);
    expect(download.suggestedFilename()).toMatch(/^analytics-approval-summary-\d{4}-\d{2}-\d{2}\.csv$/);
    const lines = (await readFile(await download.path(), 'utf8')).split('\n');
    expect(lines[0]).toBe(APPROVAL_SUMMARY_HEADER);
    expect(lines[1]).toMatch(APPROVAL_SUMMARY_ROW);
    expect(lines.slice(2), 'nothing after the row but the line end').toEqual(['']);
  });

  // Regression: the API answered every CSV export with 500, with or without
  // rows. Its action returned ['data' => null] after it had put the CSV into
  // the response, so the framework made that array the content of a raw
  // response, which it refuses ("Response content must not be an array.").
  // The PHPUnit tests called the action directly and never sent the response.
  test('the API exports a report as CSV, and a report without rows as an empty CSV', async ({ page, request, baseURL }) => {
    const tokenName = `e2e-analytics-csv-${Date.now()}`;
    const token = await generateApiToken(page, tokenName);
    try {
      const headers = { Authorization: `Bearer ${token}` };

      // First: without a row no CSV is written, so only the answer can fail.
      const empty = await request.get(`${baseURL}/api/v1/analytics/job-trend?format=csv&${EMPTY_RANGE}`, { headers });
      expect(empty.status(), 'the job trend of a range without jobs as CSV').toBe(200);
      expect(empty.headers()['content-type']).toBe(CSV_CONTENT_TYPE);
      expect(await empty.text()).toBe('');

      const summary = await request.get(`${baseURL}/api/v1/analytics/approval-summary?format=csv`, { headers });
      expect(summary.status(), 'the approval summary as CSV').toBe(200);
      expect(summary.headers()['content-type']).toBe(CSV_CONTENT_TYPE);
      expect(summary.headers()['content-disposition']).toMatch(/^attachment; filename="analytics-approvalSummary-\d{4}-\d{2}-\d{2}\.csv"$/);
      const lines = (await summary.text()).split('\n');
      expect(lines[0]).toBe(APPROVAL_SUMMARY_HEADER);
      expect(lines[1]).toMatch(APPROVAL_SUMMARY_ROW);
      expect(lines.slice(2), 'nothing after the row but the line end').toEqual(['']);
    } finally {
      await revokeApiToken(page, tokenName);
    }
  });
});

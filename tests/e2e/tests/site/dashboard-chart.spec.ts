import { test, expect, Page } from '@playwright/test';
import { LOGIN_PASSWORD, LOGIN_SUBMIT, LOGIN_USERNAME } from '../../lib/selectors';
import { E2E_ADMIN, asUser } from '../../lib/session';

/**
 * The dashboard's charts (views/site/index.php) come from /site/chart-data,
 * fetched when the page loads and when the range changes. The fetch asks for
 * JSON; an answer that is not OK, or none, leaves the charts as they are.
 */

function isChartData(url: string): boolean {
  return url.includes('/site/chart-data?days=');
}

/** Whether Chart.js drew a chart on the canvas with this ID. */
async function hasChart(page: Page, canvasId: string): Promise<boolean> {
  return page.evaluate((id) => {
    const chart = (window as unknown as { Chart?: { getChart(id: string): unknown } }).Chart;
    return chart !== undefined && chart.getChart(id) !== undefined;
  }, canvasId);
}

test.describe('Dashboard charts', () => {
  test('the chart fetch asks for JSON and draws both charts', async ({ page }) => {
    const fetched = page.waitForRequest((request) => isChartData(request.url()));
    await page.goto('/site/index');

    expect((await fetched).headers()['accept']).toBe('application/json');
    await expect.poll(() => hasChart(page, 'chart-jobs')).toBe(true);
    await expect.poll(() => hasChart(page, 'chart-tasks')).toBe(true);
  });

  // regression: a fetch that was not answered OK ended in an error that
  // nothing caught: the script read every answer as JSON (git show
  // HEAD:views/site/index.php, `.then(function (r) { return r.json(); })`).
  test('a chart fetch that fails leaves the page working', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    let failed = 0;
    const chartData = (url: URL) => isChartData(url.toString());
    await page.route(chartData, async (route) => {
      failed++;
      await route.fulfill({ status: 500, contentType: 'text/html', body: '<html><body>Internal Server Error</body></html>' });
    });
    await page.goto('/site/index');
    await expect.poll(() => failed).toBe(1);
    await page.unroute(chartData);

    // The range still loads the charts.
    const answered = page.waitForResponse((response) => isChartData(response.url()) && response.status() === 200);
    await page.locator('#chart-range').selectOption('7');
    await answered;
    await expect.poll(() => hasChart(page, 'chart-jobs')).toBe(true);
    expect(errors, 'no error the page did not catch').toEqual([]);
  });

  // regression: after a sign-out, signing in again led to the chart data
  // (JSON) instead of the dashboard. The signed-out fetch asked for
  // anything (fetch's Accept: */*), so the server sent it to the login
  // page and kept /site/chart-data as the address to return to after the
  // next sign-in. In a session of its own, so no other spec's session ends.
  test('regression: after a sign-out, signing in again led to the chart data', async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (page) => {
      const errors: string[] = [];
      page.on('pageerror', (error) => errors.push(error.message));
      // Signed in, the user lands on the dashboard: once its charts are
      // drawn and nothing is in flight, no answer to a signed-in fetch can
      // be taken for the one below.
      await page.goto('/site/index');
      await page.waitForLoadState('networkidle');
      await expect.poll(() => hasChart(page, 'chart-jobs')).toBe(true);

      // Signed out in another tab: the session is gone.
      await page.context().clearCookies();
      const refused = page.waitForResponse((response) => isChartData(response.url()));
      await page.locator('#chart-range').selectOption('7');
      expect((await refused).status(), 'the fetch of a signed-out page is refused, not sent to the login page').toBe(403);
      expect(errors, 'the refusal leaves the page working').toEqual([]);

      await page.goto('/site/login');
      await page.locator(LOGIN_USERNAME).fill(E2E_ADMIN.username);
      await page.locator(LOGIN_PASSWORD).fill(E2E_ADMIN.password);
      await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/site/login'), { timeout: 10_000 }),
        page.locator(LOGIN_SUBMIT).click(),
      ]);
      expect(new URL(page.url()).pathname, 'signed in again, the dashboard').toBe('/');
    });
  });
});

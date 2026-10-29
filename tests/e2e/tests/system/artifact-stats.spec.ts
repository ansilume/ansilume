import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Artifact Storage', () => {
  test('admin page loads with stat cards', async ({ page }) => {
    await page.goto('/system/artifact-stats');
    await expect(page.locator(PAGE_TITLE)).toContainText(/artifact storage/i);

    // Three top-level stat cards: total bytes, artifacts, jobs.
    const cards = page.locator('.row .card .card-body');
    const count = await cards.count();
    expect(count).toBeGreaterThanOrEqual(3);
  });

  // The Global byte quota row shows how much of the quota the artifacts use,
  // in whole percent and at most 100 (app\components\Percent::ofQuota():
  // 575 MB of 1000 MB is 58 %; since PHP 8.4 used / quota * 100 rounded
  // that exact half down to 57). Only tests/integration/controllers/
  // SystemControllerActionTest.php covers it
  // (testRegressionTheQuotaUseRoundedAnExactHalfDown,
  // testWithoutAQuotaTheRowShowsNoUse): the row shows a use only with
  // ARTIFACT_MAX_TOTAL_BYTES set, which config/services.php reads when the
  // app starts, and the E2E stack sets none; a spec cannot set it without
  // restarting the app container.
  test('configuration table is visible', async ({ page }) => {
    await page.goto('/system/artifact-stats');
    await expect(page.locator('text=Configuration').first()).toBeVisible();
    await expect(page.locator('th:has-text("Retention")').first()).toBeVisible();
    await expect(page.locator('th:has-text("Max file size")').first()).toBeVisible();
  });

  test('top jobs table or empty state visible', async ({ page }) => {
    await page.goto('/system/artifact-stats');
    await expect(page.locator('text=Top jobs by artifact size').first()).toBeVisible();
  });
});

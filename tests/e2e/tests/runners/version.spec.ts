import { test, expect } from '@playwright/test';

// Regression coverage for the runner-version telemetry feature.
// - Dashboard tile for runners surfaces an "outdated" warning.
// - Runner-group/view shows a per-runner Version column.
//
// These specs only assert UI structure, not state (we can't easily
// synthesise an outdated runner from the browser). They pin that the
// version column exists and that the dashboard's runner card renders
// without errors — the in-depth behavioural coverage is in the PHP
// unit/integration tests (RunnerTest, RunnerHeartbeatVersionTest).

test.describe('Runner version visibility', () => {
  test('dashboard renders the runner tile', async ({ page }) => {
    await page.goto('/');
    // The tile links to the runner-group index under the "Runners Online" label.
    await expect(page.locator('a', { hasText: /Runners Online/i }).first()).toBeVisible({
      timeout: 5_000,
    });
  });

  test('runner-group detail page shows a Version column', async ({ page }) => {
    await page.goto('/runner-group/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (!(await firstRow.isVisible({ timeout: 3_000 }).catch(() => false))) {
      test.skip(true, 'No runner groups seeded');
      return;
    }
    await firstRow.click();
    await page.waitForLoadState('domcontentloaded');

    // Wait for the runners table (if any runners are registered).
    const hasRunners = await page
      .locator('table.table tbody tr')
      .first()
      .isVisible({ timeout: 2_000 })
      .catch(() => false);

    if (!hasRunners) {
      test.skip(true, 'No runners registered in this group');
      return;
    }

    // The Version header must be present.
    const versionHeader = page.locator('table.table thead th', { hasText: /^Version$/ });
    await expect(versionHeader).toBeVisible();

    // The first data row must include either a version badge or "unknown".
    const firstDataRow = page.locator('table.table tbody tr').first();
    const rowText = await firstDataRow.innerText();
    expect(rowText).toMatch(/unknown|\d+\.\d+\.\d+/);
  });
});

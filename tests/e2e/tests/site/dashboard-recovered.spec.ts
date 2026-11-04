import { test } from '@playwright/test';
import {
  expectTheDashboardLeavesOutTheRecoveredFailure,
  expectTheJobPageNamesTheRecoveringRun,
  expectTheListKeepsTheWindowOfTheDashboard,
  recoveryJobs,
} from '../../lib/recovery';

/**
 * A failed job that a later run of the same job template recovered no longer
 * shows as an open failure: the dashboard leaves it out of Failed Jobs (last
 * 24h) and counts it in a muted line that links the job list of the recovered
 * failures of those 24 hours, and its job page names the run that recovered
 * it. As e2e-admin; e2e-operator and e2e-viewer see the same
 * (site/rbac.spec.ts, jobs/rbac.spec.ts).
 *
 * Fixtures: commands/E2eRecoveredFailureSeeder.php (lib/recovery.ts).
 */
test.describe('Dashboard — recovered failures', () => {
  test('admin: the recovered failure leaves the red panel, and a muted line counts it and links the recovered failures', async ({ page }) => {
    await expectTheDashboardLeavesOutTheRecoveredFailure(page, await recoveryJobs(page));
  });

  // Regression: the line counts the last 24 hours, but its link listed every
  // recovered failure, of any age. The list keeps the window (finished_since)
  // through its filter form, and says so, with a link that removes it.
  test('admin: the list of the recovered failures keeps the window of the line, and its note removes it', async ({ page }) => {
    await expectTheListKeepsTheWindowOfTheDashboard(page, await recoveryJobs(page));
  });

  test('admin: the job page of the recovered failure names the run that recovered it', async ({ page }) => {
    await expectTheJobPageNamesTheRecoveringRun(page, await recoveryJobs(page));
  });
});

import { test } from '@playwright/test';
import { expectTheDashboardLeavesOutTheRecoveredFailure, recoveryJobs } from '../../lib/recovery';

/**
 * The dashboard per role. Every signed-in user sees the dashboard, viewer
 * included, so every role sees a failed job that a later run of the same job
 * template recovered left out of Failed Jobs (last 24h), and the muted line
 * that counts such failures and links their list. The fixtures are on a
 * project that no team has (commands/E2eRecoveredFailureSeeder.php), which
 * every role may see. dashboard-recovered.spec.ts checks the same as
 * e2e-admin.
 *
 * The operator and viewer projects run this file (playwright.config.ts); each
 * runs only the tests named for it.
 */
test.describe('Dashboard RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });

  test('viewer sees a recovered failure left out of the red panel, and the muted line that counts it', async ({ page }) => {
    await expectTheDashboardLeavesOutTheRecoveredFailure(page, await recoveryJobs(page));
  });

  test('operator sees a recovered failure left out of the red panel, and the muted line that counts it', async ({ page }) => {
    await expectTheDashboardLeavesOutTheRecoveredFailure(page, await recoveryJobs(page));
  });
});

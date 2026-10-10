import { test, expect } from '@playwright/test';
import { idWhere, indexRows } from '../../lib/scoping';

/**
 * The Inbound Trigger card of a job template page is shown to users who may
 * change the template. e2e-admin may change every template, also one of a
 * project that a team only views: e2e-alpha-viewed-tmpl, whose trigger token
 * e2e-admin generated (commands/E2eTeamScopingSeeder.php). The operator and
 * viewer side: tests/team-scoping/triggers.spec.ts.
 */
test.describe('Job Template Trigger Token', () => {
  test('admin sees the trigger card and whom the trigger runs as', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/job-template/index?sort=name'), 1, 'e2e-alpha-viewed-tmpl');
    await page.goto(`/job-template/view?id=${id}`);

    await expect(page.locator('#page-content .card', { hasText: 'Inbound Trigger' })).toHaveCount(1);
    await expect(page.getByTestId('trigger-runs-as')).toContainText('Runs as e2e-admin, who generated the token.');
    await expect(page.locator('#page-content form[action*="/revoke-trigger-token"] button')).toBeVisible();
  });
});

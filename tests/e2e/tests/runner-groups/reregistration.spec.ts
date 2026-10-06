import { test, expect } from '@playwright/test';

// Self-registration under an existing runner name re-issues the token and
// revokes the old one. The runner group page shows that, and repeated resets
// get a badge, because they usually mean a duplicated or hijacked RUNNER_NAME.
test.describe('Runner re-registration notice', () => {
  test('runner group page shows token resets by self-registration', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    const row = page.locator('table.table tbody tr', { hasText: 'e2e-reregistered-runner' });
    await expect(row.getByTestId('runner-reregistered')).toContainText('Token re-issued by self-registration');
    await expect(row.getByTestId('runner-reregistered-count')).toHaveText('2× in 24 h');

    // A runner that was never re-registered shows no notice.
    const quiet = page.locator('table.table tbody tr', { hasText: 'e2e-quote-runner' });
    await expect(quiet.getByTestId('runner-reregistered')).toHaveCount(0);
  });
});

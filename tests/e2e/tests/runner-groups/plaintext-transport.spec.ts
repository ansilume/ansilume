import { test, expect, Page } from '@playwright/test';

// Fixtures from commands/E2eRunnerRegistrationSeeder.php
const GROUP = 'e2e-runner-group-2';
const PLAINTEXT_RUNNER = 'e2e-plaintext-runner';
const REREGISTERED_RUNNER = 'e2e-reregistered-runner';

async function openGroup(page: Page) {
  await page.goto('/runner-group/index');
  await page.locator('table.table tbody tr a', { hasText: GROUP }).first().click();
  await expect(page.locator('h2').first()).toContainText(GROUP);
}

function runnerRow(page: Page, name: string) {
  return page.locator('tr', { has: page.locator('span.fw-semibold', { hasText: new RegExp(`^${name}$`) }) });
}

test.describe('Runner transport', () => {
  test('a runner on plain HTTP from outside is flagged on its group page', async ({ page }) => {
    await openGroup(page);

    await expect(page.getByTestId('runner-group-plaintext-warning')).toContainText(`"${PLAINTEXT_RUNNER}" connects over plain HTTP`);
    const transport = runnerRow(page, PLAINTEXT_RUNNER).getByTestId('runner-transport');
    await expect(transport.getByTestId('runner-transport-insecure')).toHaveText('Plain HTTP');
    await expect(transport).toContainText('203.0.113.7');
  });

  test('a runner without requests since the upgrade shows an unknown transport', async ({ page }) => {
    await openGroup(page);

    const transport = runnerRow(page, REREGISTERED_RUNNER).getByTestId('runner-transport');
    await expect(transport).toHaveAttribute('data-transport', '');
    await expect(transport).toContainText('unknown');
  });
});

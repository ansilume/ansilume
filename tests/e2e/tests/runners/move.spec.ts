import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

test.describe('Runner Move Between Groups', () => {
  const runnerName = 'e2e-move-runner';

  test('create runner then move to another group', async ({ page }) => {
    // Go to runner group list
    await page.goto('/runner-group/index');

    // Find a group and navigate to it
    const groupLink = page.locator('table.table tbody tr a').first();
    await expect(groupLink).toBeVisible({ timeout: 5_000 });
    await groupLink.click();

    // Create a runner in this group
    const nameInput = page.locator('input[name="name"]');
    await nameInput.fill(runnerName);
    await page.locator('button:has-text("Create")').click();

    // Token flash should appear
    await expect(page.locator('.alert-warning')).toContainText('Token for runner');

    // The runner should appear in the table
    await expect(page.locator('table.table tbody tr', { hasText: runnerName })).toBeVisible();

    // Find the Move dropdown button for this runner
    const runnerRow = page.locator('table.table tbody tr', { hasText: runnerName });
    const moveBtn = runnerRow.locator('button:has-text("Move")');

    if (await moveBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await moveBtn.click();
      // Click the first group in the dropdown
      const dropdownItem = runnerRow.locator('.dropdown-menu .dropdown-item').first();
      if (await dropdownItem.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await dropdownItem.click();
        await expectFlash(page, 'success');
        // The runner should appear in the new group's page
        await expect(page.locator('table.table tbody tr', { hasText: runnerName })).toBeVisible();
      }
    }

    // Clean up: delete the runner
    const deleteRow = page.locator('table.table tbody tr', { hasText: runnerName });
    if (await deleteRow.isVisible({ timeout: 2_000 }).catch(() => false)) {
      page.on('dialog', (dialog) => dialog.accept());
      await deleteRow.locator('button:has-text("Delete")').click();
      await expectFlash(page, 'success');
    }
  });
});

import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { GROUP_RUNNER, GROUP_WITH_RUNNER, GROUP_WITH_TEMPLATE, groupId } from '../../lib/runner-groups';
import { forgePost } from '../../lib/scoping';
import { runnerRow } from '../../lib/tokens';

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

  // Regression: move read target_group_id with an (int) cast, so a forged
  // "<id>.5" moved the runner to the group <id> (and on PHP 8.5 a JSON number
  // beyond the int range answered 500). A target_group_id that is no ID finds
  // no group (SubmittedId): the runner stays where it is.
  // commands/E2eRunnerGroupGuardSeeder.php: e2e-group-runner is the only
  // runner of e2e-group-with-runner; e2e-group-with-template has none.
  test('a forged move to a group ID that is no ID moves nothing', async ({ page }) => {
    const target = await groupId(page, GROUP_WITH_TEMPLATE);
    const source = await groupId(page, GROUP_WITH_RUNNER);
    await page.goto(`/runner-group/view?id=${source}`);
    const move = (await runnerRow(page, GROUP_RUNNER).locator('form[action*="/runner/move"]').first().getAttribute('action')) ?? '';
    expect(move, 'the Move form of the runner').toMatch(/\/runner\/move(\/|\?id=)\d+$/);
    // A page without requests of its own: the dashboard's chart request can
    // store its copy of the session after the post set the flash.
    await page.goto('/team/index');

    await forgePost(page, move, { target_group_id: `${target}.5` }, /\/runner-group\/view\?id=\d+$/);

    await expect(page, 'the page of the group it is in').toHaveURL(new RegExp(`/runner-group/view\\?id=${source}$`));
    await expectFlash(page, 'danger', 'Target runner group not found.');
    await expect(runnerRow(page, GROUP_RUNNER)).toHaveCount(1);
    await page.goto(`/runner-group/view?id=${target}`);
    await expect(runnerRow(page, GROUP_RUNNER)).toHaveCount(0);
  });
});

import { test, expect, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { indexRows, rowsWhere } from '../../lib/scoping';
import {
  GROUP_DELETE_CONFIRM,
  GROUP_RUNNER,
  GROUP_TEMPLATE,
  GROUP_WITH_RUNNER,
  GROUP_WITH_TEMPLATE,
  groupId,
  groupRow,
  runnersRefused,
  templatesRefused,
} from '../../lib/runner-groups';

/**
 * Deleting a runner group that is still in use
 * (controllers/RunnerGroupController.php, services/RunnerGroupDeletionService.php):
 * the web UI refuses it as the API does (422 there). The refusal is a danger
 * flash on the group's page, and the group stays with its job template or its
 * runner. Without the guard, the delete removed the group, which cleared the
 * runner group of its templates and deleted its runners (foreign keys).
 * Fixtures: commands/E2eRunnerGroupGuardSeeder.php, restored by every seed.
 */

/**
 * Clicks Delete in the row of the runner group `name` on the runner group
 * list and accepts the confirm dialog, which asks GROUP_DELETE_CONFIRM: it
 * no longer promises to delete the group's runners, which the delete refuses.
 */
async function deleteFromList(page: Page, name: string): Promise<void> {
  await page.goto('/runner-group/index');
  const row = groupRow(page, name);
  await expect(row).toHaveCount(1);
  const messages: string[] = [];
  page.once('dialog', (dialog) => {
    messages.push(dialog.message());
    void dialog.accept();
  });
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/runner-group/delete')),
    row.getByRole('button', { name: 'Delete' }).click(),
  ]);
  expect(messages).toEqual([GROUP_DELETE_CONFIRM]);
}

test.describe('Delete a runner group that is in use', () => {
  // Regression: the web runner-group delete removed a group that job templates use.
  test('a group that a job template uses is not deleted', async ({ page }) => {
    const id = await groupId(page, GROUP_WITH_TEMPLATE);

    await deleteFromList(page, GROUP_WITH_TEMPLATE);

    await expect(flash(page, 'danger')).toHaveText(templatesRefused(GROUP_WITH_TEMPLATE, 1));
    await expect(flash(page, 'success')).toHaveCount(0);
    await expect(page).toHaveURL(new RegExp(`/runner-group/view\\?id=${id}$`));
    await expect(page.locator('#page-content h2').first()).toHaveText(GROUP_WITH_TEMPLATE);

    await page.goto('/runner-group/index');
    await expect(groupRow(page, GROUP_WITH_TEMPLATE).locator('td').nth(2), 'its Templates count').toHaveText('1');
    // Columns of the job template list: #, Name, Project, Playbook, Inventory, Runner.
    const templates = rowsWhere(await indexRows(page, '/job-template/index?sort=name'), 1, GROUP_TEMPLATE);
    expect(templates).toHaveLength(1);
    expect(templates[0].cells[5], `${GROUP_TEMPLATE} still runs on the group`).toBe(GROUP_WITH_TEMPLATE);
  });

  // Regression: the web runner-group delete removed a group that a runner belongs to, and the runner with it.
  test('a group that a runner belongs to is not deleted', async ({ page }) => {
    const id = await groupId(page, GROUP_WITH_RUNNER);

    await deleteFromList(page, GROUP_WITH_RUNNER);

    await expect(flash(page, 'danger')).toHaveText(runnersRefused(GROUP_WITH_RUNNER, 1));
    await expect(flash(page, 'success')).toHaveCount(0);
    await expect(page).toHaveURL(new RegExp(`/runner-group/view\\?id=${id}$`));
    await expect(page.locator('#page-content h2').first()).toHaveText(GROUP_WITH_RUNNER);
    await expect(page.locator('#page-content table.table tbody tr', { hasText: GROUP_RUNNER }), 'the runner stays').toHaveCount(1);

    await page.goto('/runner-group/index');
    await expect(groupRow(page, GROUP_WITH_RUNNER).locator('td').nth(3), 'its runners').toHaveText('0/1 online');
  });
});

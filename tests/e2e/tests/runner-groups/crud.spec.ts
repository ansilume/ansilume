import { test, expect } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { expectFlash, fillForm, submitForm, getTableRowCount } from '../../lib/helpers';
import { GROUP_DELETE_CONFIRM, groupRow, removeGroupsNamed } from '../../lib/runner-groups';
import { PAGE_TITLE } from '../../lib/selectors';

/** The group this spec creates, updates and deletes. */
const CRUD_GROUP = 'e2e-crud-runner-group';

test.describe('Runner Groups CRUD', () => {
  test('index page lists runner groups', async ({ page }) => {
    await page.goto('/runner-group/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/runner/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view runner group detail', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/runner|group|token/i);
  });

  test('create runner group', async ({ page }) => {
    await page.goto('/runner-group/create');
    await fillForm(page, 'runnergroup', {
      name: CRUD_GROUP,
      description: 'Created by E2E test',
    });
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update runner group', async ({ page }) => {
    await page.goto('/runner-group/index');
    const row = page.locator('table.table tbody tr', { hasText: CRUD_GROUP });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#runnergroup-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  // Regression: the list's Delete asked 'Delete this runner group and all its
  // runners?', but a group that runners belong to is not deleted
  // (runner-groups/delete-in-use.spec.ts): the confirm promised what the
  // delete refuses.
  test('delete runner group asks "Delete this runner group?"', async ({ page }) => {
    try {
      await page.goto('/runner-group/index');
      await expect(groupRow(page, CRUD_GROUP)).toHaveCount(1);
      const messages: string[] = [];

      page.once('dialog', (dialog) => {
        messages.push(dialog.message());
        void dialog.dismiss();
      });
      await groupRow(page, CRUD_GROUP).getByRole('button', { name: 'Delete' }).click();
      await expect.poll(() => messages.length).toBe(1);
      expect(messages[0]).toBe(GROUP_DELETE_CONFIRM);
      await page.reload();
      await expect(groupRow(page, CRUD_GROUP), 'a dismissed confirm deletes nothing').toHaveCount(1);

      page.once('dialog', (dialog) => {
        messages.push(dialog.message());
        void dialog.accept();
      });
      await Promise.all([
        page.waitForNavigation({ url: /\/runner-group\/index$/ }),
        groupRow(page, CRUD_GROUP).getByRole('button', { name: 'Delete' }).click(),
      ]);
      expect(messages).toEqual([GROUP_DELETE_CONFIRM, GROUP_DELETE_CONFIRM]);
      await expect(flash(page, 'success')).toHaveText('Runner group deleted.');
      await expect(groupRow(page, CRUD_GROUP)).toHaveCount(0);
    } finally {
      // A failed check must not leave the group behind for the next run's create.
      await removeGroupsNamed(page, CRUD_GROUP);
    }
  });
});

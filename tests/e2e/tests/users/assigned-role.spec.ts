import { test, expect, Locator, Page } from '@playwright/test';
import { expectFlash, fillForm, submitForm } from '../../lib/helpers';

/**
 * regression: every signed-in user holds the default role viewer
 * (config/web.php), and the user pages read a user's roles with the default
 * role first. The Edit User form preselected Viewer for every user, so saving
 * an operator's or an admin's account unchanged made them a viewer, and the
 * users list and the user page showed viewer beside every role. They show and
 * keep the roles assigned to the user now (app\services\AssignedRoles).
 *
 * The seeded e2e users are only looked at, never saved: the save tests create
 * their own users e2e-assigned-role-<suffix> and e2e-role-choice-<suffix> and
 * delete them.
 *
 * e2e-admin is a superadmin: the Role field offers it every role and the
 * Superadmin field (app\services\RoleGrantPolicy; what users who are no
 * superadmins may give is covered by UserControllerActionTest).
 */

/** The Role column of the users list: #, Username, Email, Source, Role. */
const ROLE_COLUMN = 4;

/**
 * The row of `username` in the users list, which is ordered by ID and may
 * span pages. Yii answers a page number past the end with the last page, so
 * the search stops when a page repeats the previous one.
 */
async function userRow(page: Page, username: string): Promise<Locator> {
  let previousFirstId = '';
  for (let pageNo = 1; pageNo <= 50; pageNo++) {
    await page.goto(`/user/index?page=${pageNo}`);
    const rows = page.locator('#user-table tbody tr');
    const row = rows.filter({ has: page.getByRole('link', { name: username, exact: true }) });
    if ((await row.count()) > 0) {
      await expect(row, `exactly one row of ${username}`).toHaveCount(1);
      return row;
    }
    const firstId = ((await rows.first().locator('td').first().textContent()) ?? '').trim();
    if (firstId === previousFirstId) {
      break;
    }
    previousFirstId = firstId;
  }
  throw new Error(`${username} is not in the users list`);
}

/** The role badges under Roles on a user's page. */
function pageRoleBadges(page: Page): Locator {
  return page.locator('#page-content dl dt:text-is("Roles") + dd .badge');
}

/** Deletes user #`id` from its page, as an admin does. */
async function deleteUser(page: Page, id: string): Promise<void> {
  await page.goto(`/user/view?id=${id}`);
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#page-content form[action*="/user/delete"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/user\/index/);
  await expectFlash(page, 'success', 'deleted.');
}

test.describe('Users: the assigned role, not the default role', () => {
  test('regression: the Edit User page of e2e-operator preselects the operator role', async ({ page }) => {
    const row = await userRow(page, 'e2e-operator');
    await row.getByRole('link', { name: 'Edit', exact: true }).click();

    await expect(page).toHaveURL(/\/user\/update\?id=\d+/);
    await expect(page.locator('#userform-role')).toHaveValue('operator');
    // Not saved: the seeded user keeps its role for the other specs.
  });

  test('regression: the users list row of e2e-operator shows only its role badge', async ({ page }) => {
    const row = await userRow(page, 'e2e-operator');

    await expect(row.locator('td').nth(ROLE_COLUMN).locator('.badge')).toHaveText(['operator']);
  });

  test('regression: an operator saved again after changing only the email stays an operator', async ({ page }) => {
    const username = `e2e-assigned-role-${Date.now().toString(36)}`;
    let userId = '';
    try {
      await page.goto('/user/create');
      await fillForm(page, 'userform', {
        username,
        email: `${username}@example.com`,
        password: 'E2eAssignedRole1!',
        role: 'operator',
      });
      await submitForm(page);
      await expect(page).toHaveURL(/\/user\/view\?id=\d+/);
      userId = new URL(page.url()).searchParams.get('id') ?? '';
      await expectFlash(page, 'success', `User "${username}" created.`);
      await expect(pageRoleBadges(page)).toHaveText(['operator']);

      await page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true }).click();
      await expect(page.locator('#userform-role')).toHaveValue('operator');
      await page.locator('#userform-email').fill(`${username}-changed@example.com`);
      await submitForm(page);

      await expect(page).toHaveURL(new RegExp(`/user/view\\?id=${userId}$`));
      await expectFlash(page, 'success', `User "${username}" updated.`);
      await expect(page.locator('#page-content dl')).toContainText(`${username}-changed@example.com`);
      await expect(pageRoleBadges(page)).toHaveText(['operator']);
    } finally {
      if (userId !== '') {
        await deleteUser(page, userId);
      }
    }
  });

  test('a superadmin is offered every role and the Superadmin field; another role replaces the role, an unchanged save keeps it', async ({ page }) => {
    const username = `e2e-role-choice-${Date.now().toString(36)}`;
    let userId = '';
    try {
      await page.goto('/user/create');
      await fillForm(page, 'userform', {
        username,
        email: `${username}@example.com`,
        password: 'E2eRoleChoice1!',
        role: 'operator',
      });
      await submitForm(page);
      await expect(page).toHaveURL(/\/user\/view\?id=\d+/);
      userId = new URL(page.url()).searchParams.get('id') ?? '';
      await expect(pageRoleBadges(page)).toHaveText(['operator']);

      await page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true }).click();
      const role = page.locator('#userform-role');
      const offered = await role.locator('option').evaluateAll(
        (els) => els.map((el) => (el as HTMLOptionElement).value),
      );
      expect(offered).toEqual(expect.arrayContaining(['viewer', 'operator', 'admin']));
      await expect(role).toHaveValue('operator');
      await expect(page.locator('#userform-is_superadmin')).toBeVisible();
      await expect(page.locator('#userform-is_superadmin')).not.toBeChecked();

      await role.selectOption('viewer');
      await submitForm(page);
      await expect(page).toHaveURL(new RegExp(`/user/view\\?id=${userId}$`));
      await expectFlash(page, 'success', `User "${username}" updated.`);
      await expect(pageRoleBadges(page)).toHaveText(['viewer']);

      await page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true }).click();
      await expect(role).toHaveValue('viewer');
      await submitForm(page);
      await expect(page).toHaveURL(new RegExp(`/user/view\\?id=${userId}$`));
      await expectFlash(page, 'success', `User "${username}" updated.`);
      await expect(pageRoleBadges(page)).toHaveText(['viewer']);
      await expect(page.locator('#page-content dl dt:text-is("Superadmin") + dd')).toHaveText('No');
    } finally {
      if (userId !== '') {
        await deleteUser(page, userId);
      }
    }
  });
});

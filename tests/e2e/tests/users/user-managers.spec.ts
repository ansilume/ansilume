import { test, expect, Locator, Page } from '@playwright/test';
import { expectFlash, fillForm, postWithPageCsrf, submitForm, userId } from '../../lib/helpers';
import { asUser, E2E_HELP_DESK } from '../../lib/session';

/**
 * regression: whoever held user.update could set the password and the email
 * of any user, a superadmin included, and then sign in as them; with
 * user.delete they could deactivate or delete them. A user who is no
 * superadmin may change only a user who is no superadmin and all of whose
 * roles they may assign, and, unless they hold the admin role, whose teams
 * give that user no project beyond what their own teams give them
 * (app\services\UserChangePolicy): the users list and the user page offer
 * Edit, Deactivate and Delete only for those, and whatever changes anyone
 * else answers 403.
 *
 * Fixtures (commands/E2eUserManagerSeeder.php): e2e-help-desk holds only
 * e2e-user-manager (user.view, user.update, user.delete) and the default role
 * viewer, and is in no team; e2e-multi-role holds operator and
 * e2e-second-role; e2e-member-viewer holds only viewer and is the only member
 * of e2e-member-team, which views the restricted e2e-member-project.
 * e2e-admin is a superadmin with the admin role, e2e-operator an operator.
 * The seeded users are only looked at or saved unchanged; the test that
 * changes a user creates e2e-hd-target-<suffix>, in no team, and deletes it.
 */

/** app\services\UserChangePolicy::CHANGE_REFUSED. */
const CHANGE_REFUSED = 'You cannot change or delete this user: they are a superadmin, or a role assigned to them grants permissions you do not hold.';

/** app\services\UserChangePolicy::TEAM_ACCESS_REFUSED. */
const TEAM_ACCESS_REFUSED = 'You cannot change or delete this user: their teams give them more access to a project than you have.';

/** commands/E2eUserManagerSeeder.php TEAM_MEMBER: a viewer whose team views a restricted project. */
const TEAM_MEMBER = 'e2e-member-viewer';

/** app\models\UserForm::SEVERAL_ROLES_HINT for e2e-multi-role: its roles, sorted by name. */
const MULTI_ROLE_HINT = 'Assigned roles: e2e-second-role, operator. Saving with the role shown keeps all of them; another role replaces them all.';

const MULTI_ROLE = 'e2e-multi-role';

/** The role the Edit User form shows for e2e-multi-role: its first by name. */
const SECOND_ROLE = 'e2e-second-role';

/** Users the help desk may not change: a superadmin and admin, an operator, an operator with a second role. */
const PROTECTED_USERS = ['e2e-admin', 'e2e-operator', MULTI_ROLE];

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

/** Edit on a user's page, and the forms of Deactivate/Activate and Delete. */
function pageActions(page: Page): Locator[] {
  return [
    page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true }),
    page.locator('#page-content form[action*="/user/toggle-status"]'),
    page.locator('#page-content form[action*="/user/delete"]'),
  ];
}

test.describe('Users: user managers change only users whose roles they may assign', () => {
  test('regression: the help desk is offered nothing that changes e2e-admin, e2e-operator or e2e-multi-role, and their Edit User pages answer 403', async ({ browser, page }, testInfo) => {
    const ids: Record<string, string> = {};
    for (const username of PROTECTED_USERS) {
      ids[username] = await userId(page, username);
    }

    await asUser(browser, testInfo, E2E_HELP_DESK, async (helpDesk) => {
      for (const username of PROTECTED_USERS) {
        const row = await userRow(helpDesk, username);
        await expect(row.getByRole('link', { name: 'Edit', exact: true }), `${username}: no Edit in the list`).toHaveCount(0);

        await helpDesk.goto(`/user/view?id=${ids[username]}`);
        await expect(helpDesk.locator('#page-content h2').first()).toHaveText(username);
        for (const action of pageActions(helpDesk)) {
          await expect(action, `${username}: nothing that changes them`).toHaveCount(0);
        }

        const response = await helpDesk.goto(`/user/update?id=${ids[username]}`);
        expect(response?.status(), `${username}: the Edit User page`).toBe(403);
        await expect(helpDesk.locator('body')).toContainText(CHANGE_REFUSED);
      }

      // The server refuses a forged form too, and changes nothing.
      const adminId = ids['e2e-admin'];
      await helpDesk.goto(`/user/view?id=${adminId}`);
      for (const action of ['toggle-status', 'delete']) {
        const refused = await postWithPageCsrf(helpDesk, `/user/${action}?id=${adminId}`);
        expect(refused.status(), `POST /user/${action} for e2e-admin`).toBe(403);
      }
      await helpDesk.goto(`/user/view?id=${adminId}`);
      await expect(helpDesk.locator('#page-content dl dt:text-is("Status") + dd')).toHaveText('Active');
    });
  });

  test('regression: the help desk, in no team, is offered nothing that changes e2e-member-viewer, whose team has a restricted project, and its Edit User page answers 403; an admin may edit them', async ({ browser, page }, testInfo) => {
    const id = await userId(page, TEAM_MEMBER);

    await asUser(browser, testInfo, E2E_HELP_DESK, async (helpDesk) => {
      const row = await userRow(helpDesk, TEAM_MEMBER);
      await expect(row.getByRole('link', { name: 'Edit', exact: true }), 'no Edit in the list').toHaveCount(0);

      await helpDesk.goto(`/user/view?id=${id}`);
      await expect(helpDesk.locator('#page-content h2').first()).toHaveText(TEAM_MEMBER);
      await expect(helpDesk.locator('#page-content dl dt:text-is("Roles") + dd .badge'), 'a role the help desk may assign').toHaveText(['viewer']);
      for (const action of pageActions(helpDesk)) {
        await expect(action, 'nothing that changes them').toHaveCount(0);
      }

      // The server refuses a forged form too, and changes nothing.
      const refused = await postWithPageCsrf(helpDesk, `/user/toggle-status?id=${id}`);
      expect(refused.status(), 'POST /user/toggle-status').toBe(403);

      const response = await helpDesk.goto(`/user/update?id=${id}`);
      expect(response?.status(), 'the Edit User page').toBe(403);
      await expect(helpDesk.locator('body')).toContainText(TEAM_ACCESS_REFUSED);
    });

    // The admin role reaches every project: e2e-admin may edit them.
    const row = await userRow(page, TEAM_MEMBER);
    await row.getByRole('link', { name: 'Edit', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/user/update\\?id=${id}$`));
    await expect(page.locator('#userform-role')).toHaveValue('viewer');
    await page.goto(`/user/view?id=${id}`);
    await expect(page.locator('#page-content dl dt:text-is("Status") + dd')).toHaveText('Active');
  });

  test('the help desk edits, deactivates and deletes a viewer', async ({ browser, page }, testInfo) => {
    const username = `e2e-hd-target-${Date.now().toString(36)}`;
    let id = '';
    try {
      await page.goto('/user/create');
      await fillForm(page, 'userform', {
        username,
        email: `${username}@example.com`,
        password: 'E2eHdTargetPass1!',
        role: 'viewer',
      });
      await submitForm(page);
      await expect(page).toHaveURL(/\/user\/view\?id=\d+/);
      id = new URL(page.url()).searchParams.get('id') ?? '';
      await expectFlash(page, 'success', `User "${username}" created.`);

      await asUser(browser, testInfo, E2E_HELP_DESK, async (helpDesk) => {
        const row = await userRow(helpDesk, username);
        await row.getByRole('link', { name: 'Edit', exact: true }).click();
        await expect(helpDesk).toHaveURL(new RegExp(`/user/update\\?id=${id}$`));
        await helpDesk.locator('#userform-email').fill(`${username}-changed@example.com`);
        await submitForm(helpDesk);
        await expect(helpDesk).toHaveURL(new RegExp(`/user/view\\?id=${id}$`));
        await expectFlash(helpDesk, 'success', `User "${username}" updated.`);
        await expect(helpDesk.locator('#page-content dl')).toContainText(`${username}-changed@example.com`);

        const toggle = helpDesk.locator('#page-content form[action*="/user/toggle-status"] button[type="submit"]');
        await expect(toggle).toHaveText('Deactivate');
        helpDesk.once('dialog', (dialog) => dialog.accept());
        await toggle.click();
        await expect(toggle).toHaveText('Activate');
        await expectFlash(helpDesk, 'success', `User "${username}" deactivated.`);

        helpDesk.once('dialog', (dialog) => dialog.accept());
        await helpDesk.locator('#page-content form[action*="/user/delete"] button[type="submit"]').click();
        await expect(helpDesk).toHaveURL(/\/user\/index/);
        await expectFlash(helpDesk, 'success', `User "${username}" deleted.`);
      });
      id = '';
    } finally {
      if (id !== '') {
        await page.goto(`/user/view?id=${id}`);
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('#page-content form[action*="/user/delete"] button[type="submit"]').click();
        await expect(page).toHaveURL(/\/user\/index/);
      }
    }
  });

  test('the Edit User page of e2e-multi-role names both roles, and saving it unchanged keeps them', async ({ page }) => {
    const row = await userRow(page, MULTI_ROLE);
    await row.getByRole('link', { name: 'Edit', exact: true }).click();
    await expect(page).toHaveURL(/\/user\/update\?id=\d+/);

    await expect(page.locator('.field-userform-role .hint-block')).toHaveText(MULTI_ROLE_HINT);
    await expect(page.locator('#userform-role')).toHaveValue(SECOND_ROLE);
    await submitForm(page);

    await expect(page).toHaveURL(/\/user\/view\?id=\d+/);
    await expectFlash(page, 'success', `User "${MULTI_ROLE}" updated.`);
    await expect(page.locator('#page-content dl dt:text-is("Roles") + dd .badge')).toHaveText([SECOND_ROLE, 'operator']);
  });
});

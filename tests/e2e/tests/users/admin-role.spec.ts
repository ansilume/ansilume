import { test, expect, Locator, Page, Response } from '@playwright/test';
import { fillForm, postWithPageCsrf, submitForm, userId } from '../../lib/helpers';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { asUser, E2E_ALLPERM_USER } from '../../lib/session';

/**
 * regression: the admin role grants more than its permissions, every
 * team-restricted project, yet a user who held every permission of the admin
 * role through a role of their own could assign the admin role, change a
 * user who holds it and change the role itself. Only superadmins and holders
 * of the admin role do that now (app\services\RoleGrantPolicy,
 * UserChangePolicy, RoleChangePolicy): the Role field offers such a user no
 * admin and a save with it anyway is refused, the users list and the user
 * page offer nothing that changes an admin, the admin role's page offers no
 * Edit or Delete, and both Edit pages answer 403.
 *
 * regression: the Edit User page of an admin told such a user
 * UserChangePolicy::CHANGE_REFUSED, "a role assigned to them grants
 * permissions you do not hold", untrue for one who holds every permission of
 * the admin role. It names the admin role now
 * (UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED).
 *
 * regression (wave 5g): where the admin's teams also give them a project
 * that the teams of such a user do not, the page still told them
 * CHANGE_REFUSED. It names the admin role there too, as the admin role would
 * lift the team rule as well.
 *
 * Fixtures (commands/E2eAdminRoleSeeder.php): e2e-allperm-user holds only
 * e2e-allperm-role, which holds every permission of the admin role as its
 * own but not the admin role, and is in no team; e2e-dormant-chief holds the
 * admin role, is no superadmin and is inactive, so it approves nothing.
 * Neither is changed. The refused save creates no user; should it create
 * one (e2e-ar-refused-<suffix>), the test deletes it. For the team case,
 * the test makes e2e-dormant-chief the only member of a team of its own
 * (e2e-ar-team-<suffix>) that views e2e-beta-proj, a project of team-beta
 * (commands/E2eTeamScopingSeeder.php), and deletes the team afterwards.
 * Being inactive, e2e-dormant-chief neither signs in nor approves, and the
 * tests run one at a time (workers: 1), so no other spec meets the team.
 *
 * The PHPUnit counterpart, seen failing on the code before the rule, is
 * tests/integration/commands/E2eAdminRoleSeederTest.php (testRegression*);
 * for the text of the refusal, also
 * tests/integration/services/UserChangePolicyTest.php
 * (testRegressionOnlyTheAdminRoleRefusingAnswersAdminRoleChangeRefused);
 * for the team case, tests/integration/controllers/UserChangeAdminRoleTeamsTest.php
 * and tests/integration/services/UserChangePolicyAdminRoleTeamsTest.php
 * (testRegression*).
 */

/** app\services\RoleGrantPolicy::ADMIN_ROLE_REFUSED, sprintf with 'admin'. */
const ADMIN_ROLE_REFUSED = 'You cannot assign the role "admin": it is or contains the admin role, which only superadmins and admins may assign.';

/**
 * app\services\UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, the refusal
 * UserChangePolicy::refusalOf() gives the holder for a user who holds the
 * admin role and is no superadmin, whatever the teams.
 */
const ADMIN_ROLE_CHANGE_REFUSED = 'You cannot change or delete this user: a role assigned to them is or contains the admin role, which only a superadmin or an admin may assign.';

/** app\services\UserChangePolicy::CHANGE_REFUSED, which the holder used to get for that user. */
const CHANGE_REFUSED = 'You cannot change or delete this user: they are a superadmin, or a role assigned to them grants permissions you do not hold.';

/** app\services\UserChangePolicy::TEAM_ACCESS_REFUSED, the refusal of the teams alone. */
const TEAM_ACCESS_REFUSED = 'You cannot change or delete this user: their teams give them more access to a project than you have.';

/** commands/E2eTeamScopingSeeder.php: a project of team-beta, so team-restricted; e2e-allperm-user cannot see it. */
const TEAM_PROJECT = 'e2e-beta-proj';

/** app\services\RoleChangePolicy::CHANGE_REFUSED. */
const ROLE_CHANGE_REFUSED = 'You cannot change or delete this role: you may not assign it, as it grants more than you hold.';

/** commands/E2eAdminRoleSeeder.php ROLE: the role of e2e-allperm-user. */
const ALLPERM_ROLE = 'e2e-allperm-role';

/** commands/E2eAdminRoleSeeder.php TARGET: an inactive user who holds the admin role. */
const TARGET = 'e2e-dormant-chief';

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
function userPageActions(page: Page): Locator[] {
  return [
    page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true }),
    page.locator('#page-content form[action*="/user/toggle-status"]'),
    page.locator('#page-content form[action*="/user/delete"]'),
  ];
}

/** Edit and the Delete form on a role's page. */
function rolePageActions(page: Page): Locator[] {
  return [
    page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true }),
    page.locator('#page-content form[action*="/role/delete"]'),
  ];
}

/** The values of the options of the Role field of the user form. */
async function roleOptions(page: Page): Promise<string[]> {
  return page.locator('#userform-role option').evaluateAll(
    (options) => options.map((option) => (option as HTMLOptionElement).value),
  );
}

/** The page answered 403, and the error page says exactly `message` under its heading. */
async function expectRefused(page: Page, response: Response | null, message: string): Promise<void> {
  expect(response?.status(), 'the answer').toBe(403);
  await expect(page.locator('#page-content h1 + p')).toHaveText(message);
}

/** Deletes every user named `username`, as the signed-in admin of `page`. */
async function deleteUsersNamed(page: Page, username: string): Promise<void> {
  for (const row of rowsWhere(await indexRows(page, '/user/index'), 1, username)) {
    await page.goto(`/user/view?id=${row.id}`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#page-content form[action*="/user/delete"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/user\/index/);
  }
}

/** The card of a team's page whose header starts with `title` ("Members", "Project Access"). */
function teamCard(page: Page, title: string): Locator {
  return page.locator('#page-content .card').filter({ has: page.locator('.card-header', { hasText: title }) });
}

/**
 * Creates the team `name`, which views `project`, as the signed-in admin of
 * `page`, and makes the user `memberId` its only member; returns the ids of
 * the team and of the project. The Add member field offers active users
 * only, so the member is posted as the field sends it: the server takes any
 * user.
 */
async function teamViewingProjectWith(page: Page, name: string, project: string, memberId: string): Promise<{ teamId: string; projectId: string }> {
  await page.goto('/team/create');
  await fillForm(page, 'team', { name });
  await submitForm(page);
  await expect(page).toHaveURL(/\/team\/view\?id=\d+$/);
  const teamId = new URL(page.url()).searchParams.get('id') ?? '';

  const addProject = page.locator('#add-project-form');
  await addProject.locator('select[name="project_id"]').selectOption({ label: project });
  await addProject.locator('select[name="role"]').selectOption('viewer');
  await addProject.getByRole('button', { name: 'Add', exact: true }).click();
  const projects = teamCard(page, 'Project Access');
  await expect(projects.locator('.card-header')).toHaveText('Project Access (1)');
  await expect(projects.locator('tbody tr .badge')).toHaveText('viewer');
  const projectLink = projects.locator('tbody tr a', { hasText: project });
  await expect(projectLink).toHaveCount(1);
  const projectId = ((await projectLink.getAttribute('href')) ?? '').match(/[?&]id=(\d+)/)?.[1] ?? '';
  expect(projectId, `the id of ${project}`).not.toBe('');

  const added = await postWithPageCsrf(page, `/team/add-member?id=${teamId}`, { user_id: memberId });
  expect(added.status(), 'the member is added and the page redirects to the team').toBe(302);
  await page.goto(`/team/view?id=${teamId}`);
  await expect(teamCard(page, 'Members').locator('.card-header')).toHaveText('Members (1)');
  return { teamId, projectId };
}

/** Deletes every team named `name`, as the signed-in admin of `page`; with it go its members and projects. */
async function deleteTeamsNamed(page: Page, name: string): Promise<void> {
  for (const row of rowsWhere(await indexRows(page, '/team/index'), 1, name)) {
    await page.goto(`/team/view?id=${row.id}`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#page-content form[action*="/team/delete"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/team\/index/);
  }
}

test.describe('Users: the admin role is assigned, and an admin or the role changed, only by superadmins and admins', () => {
  test('regression: the New User form offers the holder of every admin permission no admin role, and refuses a save with it sent anyway', async ({ browser, page }, testInfo) => {
    const username = `e2e-ar-refused-${Date.now().toString(36)}`;
    try {
      await asUser(browser, testInfo, E2E_ALLPERM_USER, async (holder) => {
        await holder.goto('/user/create');
        const offered = await roleOptions(holder);
        expect(offered, 'the roles the holder may assign').toEqual(expect.arrayContaining(['viewer', 'operator', ALLPERM_ROLE]));
        expect(offered, 'no admin role').not.toContain('admin');

        await fillForm(holder, 'userform', {
          username,
          email: `${username}@example.com`,
          password: 'E2eArRefusedPass1!',
        });
        // The admin role sent anyway: an option the form does not offer.
        await holder.evaluate(() => {
          const select = document.querySelector('#userform-role') as HTMLSelectElement;
          const option = document.createElement('option');
          option.value = 'admin';
          option.textContent = 'admin';
          select.appendChild(option);
          select.value = 'admin';
        });
        await submitForm(holder);

        await expect(holder).toHaveURL(/\/user\/create$/);
        await expect(holder.locator('.field-userform-role .help-block')).toHaveText(ADMIN_ROLE_REFUSED);
        expect(await roleOptions(holder), 'still no admin role').not.toContain('admin');
      });

      expect(rowsWhere(await indexRows(page, '/user/index'), 1, username), 'no user was created').toHaveLength(0);
    } finally {
      await deleteUsersNamed(page, username);
    }
  });

  test('regression: the holder of every admin permission is offered nothing that changes e2e-dormant-chief, an inactive admin, and its Edit User page answers 403 naming the admin role; a superadmin may edit them', async ({ browser, page }, testInfo) => {
    const id = await userId(page, TARGET);
    await page.goto(`/user/view?id=${id}`);
    await expect(page.locator('#page-content dl dt:text-is("Status") + dd')).toHaveText('Inactive');
    await expect(page.locator('#page-content dl dt:text-is("Superadmin") + dd')).toHaveText('No');
    await expect(page.locator('#page-content dl dt:text-is("Roles") + dd .badge')).toHaveText(['admin']);

    await asUser(browser, testInfo, E2E_ALLPERM_USER, async (holder) => {
      // The list's only action that changes a user is Edit; the user page
      // holds Deactivate and Delete.
      const row = await userRow(holder, TARGET);
      await expect(row.getByRole('link', { name: 'Edit', exact: true }), 'no Edit in the list').toHaveCount(0);
      await expect(row.locator('form'), 'no form in the list').toHaveCount(0);
      const ownRow = await userRow(holder, E2E_ALLPERM_USER.username);
      await expect(ownRow.getByRole('link', { name: 'Edit', exact: true }), 'Edit where the holder may change the user').toHaveCount(1);

      await holder.goto(`/user/view?id=${id}`);
      await expect(holder.locator('#page-content h2').first()).toHaveText(TARGET);
      for (const action of userPageActions(holder)) {
        await expect(action, 'nothing that changes them').toHaveCount(0);
      }

      await expectRefused(holder, await holder.goto(`/user/update?id=${id}`), ADMIN_ROLE_CHANGE_REFUSED);
      await expect(holder.locator('#page-content'), 'not the text that says the holder lacks permissions').not.toContainText(CHANGE_REFUSED);
    });

    // e2e-admin, a superadmin who holds the admin role, may: its Edit User
    // page opens with the admin role (not saved).
    const row = await userRow(page, TARGET);
    await row.getByRole('link', { name: 'Edit', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/user/update\\?id=${id}$`));
    await expect(page.locator('#userform-role')).toHaveValue('admin');
  });

  test('regression: in a team that views a project the holder of every admin permission cannot see, e2e-dormant-chief\'s Edit User page still answers 403 naming the admin role, not that the holder lacks permissions', async ({ browser, page }, testInfo) => {
    const id = await userId(page, TARGET);
    const team = `e2e-ar-team-${Date.now().toString(36)}`;
    try {
      const { teamId, projectId } = await teamViewingProjectWith(page, team, TEAM_PROJECT, id);
      await expect(teamCard(page, 'Members').locator('tbody td').first(), `team #${teamId}: its member`).toHaveText(TARGET);

      await asUser(browser, testInfo, E2E_ALLPERM_USER, async (holder) => {
        // The teams refuse as well: the holder, in no team and without the
        // admin role, does not reach the project the team gives TARGET.
        const project = await holder.goto(`/project/view?id=${projectId}`);
        expect(project?.status(), `the holder may not open ${TEAM_PROJECT}`).toBe(403);

        await holder.goto(`/user/view?id=${id}`);
        await expect(holder.locator('#page-content h2').first()).toHaveText(TARGET);
        for (const action of userPageActions(holder)) {
          await expect(action, 'nothing that changes them').toHaveCount(0);
        }

        await expectRefused(holder, await holder.goto(`/user/update?id=${id}`), ADMIN_ROLE_CHANGE_REFUSED);
        await expect(holder.locator('#page-content'), 'not the text that says the holder lacks permissions').not.toContainText(CHANGE_REFUSED);
        await expect(holder.locator('#page-content'), 'not the text of the teams alone').not.toContainText(TEAM_ACCESS_REFUSED);
      });
    } finally {
      await deleteTeamsNamed(page, team);
    }
    expect(rowsWhere(await indexRows(page, '/team/index'), 1, team), 'the team is gone').toHaveLength(0);
  });

  test('regression: the admin role\'s page offers the holder of every admin permission no Edit or Delete, and its Edit Role page answers 403; a superadmin may edit it', async ({ browser, page }, testInfo) => {
    await asUser(browser, testInfo, E2E_ALLPERM_USER, async (holder) => {
      await holder.goto('/role/view/admin');
      await expect(holder.locator('#page-content h2').first()).toContainText('admin');
      for (const action of rolePageActions(holder)) {
        await expect(action, 'nothing that changes the admin role').toHaveCount(0);
      }

      await expectRefused(holder, await holder.goto('/role/update/admin'), ROLE_CHANGE_REFUSED);

      // Its own role, which it may assign, is the holder's to change.
      await holder.goto(`/role/view/${ALLPERM_ROLE}`);
      for (const action of rolePageActions(holder)) {
        await expect(action, `${ALLPERM_ROLE}: Edit and Delete`).toHaveCount(1);
      }
    });

    await page.goto('/role/view/admin');
    await expect(rolePageActions(page)[0], 'e2e-admin: Edit').toHaveCount(1);
  });
});

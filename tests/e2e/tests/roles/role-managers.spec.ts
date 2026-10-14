import { test, expect, Locator, Page } from '@playwright/test';
import { expectFlash, fillForm, postWithPageCsrf, submitForm } from '../../lib/helpers';
import { asUser, E2E_ROLE_EDITOR } from '../../lib/session';

/**
 * regression: whoever held role.update could give any role, their own
 * included, any permission, and whoever held role.create could create a
 * role with any: a role editor gave its own role user.update and
 * approval-rule.update and then held both. A user who is no superadmin
 * creates roles only with permissions they hold, and changes or deletes
 * only roles they may assign, adding only permissions they hold
 * (app\services\RoleChangePolicy). The role pages offer Edit and Delete only
 * for those roles, the role form disables the permissions they do not hold,
 * and whatever changes another role answers 403.
 *
 * Fixtures (commands/E2eRoleManagerSeeder.php): e2e-role-editor holds only
 * e2e-role-manager (role.view, role.create, role.update, role.delete) and
 * the default role viewer. The roles a test creates are named
 * e2e-rm-<what>-<suffix> and deleted at its end.
 */

/** app\services\RoleChangePolicy::CHANGE_REFUSED. */
const CHANGE_REFUSED = 'You cannot change or delete this role: you may not assign it, as it grants more than you hold.';

/** app\services\RoleChangePolicy::PERMISSION_REFUSED for `permission`. */
function permissionRefused(permission: string): string {
  return `You cannot grant the permission "${permission}": you do not hold it.`;
}

/** app\models\RoleForm::NOT_HELD_HINT. */
const NOT_HELD_HINT = 'Permissions you do not hold are disabled: you can grant only what you hold.';

/** Permissions e2e-role-editor lacks: user and approval-rule management, launching. */
const NOT_HELD = ['user.view', 'user.update', 'approval-rule.update', 'job.launch'];

/** Permissions e2e-role-editor holds: through its role, and through the default role viewer. */
const HELD = ['role.view', 'role.update', 'project.view', 'job.view'];

function checkbox(page: Page, permission: string): Locator {
  return page.locator(`#role-form input[name="RoleForm[permissions][]"][value="${permission}"]`);
}

/** Edit and the Delete form on a role's page. */
function pageActions(page: Page): Locator[] {
  return [
    page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true }),
    page.locator('#page-content form[action*="/role/delete"]'),
  ];
}

/** Creates the role `name` with `permissions` as the signed-in admin. */
async function createRole(page: Page, name: string, permissions: string[]): Promise<void> {
  await page.goto('/role/create');
  await fillForm(page, 'roleform', { name, description: 'E2E role-managers spec' });
  for (const permission of permissions) {
    await checkbox(page, permission).check();
  }
  await submitForm(page);
  await expectFlash(page, 'success', `Role "${name}" created.`);
}

/** Deletes the role `name` as the signed-in admin, if it is still there. */
async function deleteRoleIfPresent(page: Page, name: string): Promise<void> {
  const response = await page.goto(`/role/view/${name}`);
  if (response?.status() !== 200) {
    return;
  }
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#page-content form[action*="/role/delete"] button[type="submit"]').click();
  await expectFlash(page, 'success', `Role "${name}" deleted.`);
}

test.describe('Roles: role managers change only roles they may assign', () => {
  test('regression: the role editor is offered nothing that changes the admin role or a role with permissions it lacks, and changing them answers 403', async ({ browser, page }, testInfo) => {
    const locked = `e2e-rm-locked-${Date.now().toString(36)}`;
    try {
      await createRole(page, locked, ['job.view', 'job.launch']);

      await asUser(browser, testInfo, E2E_ROLE_EDITOR, async (editor) => {
        for (const name of ['admin', locked]) {
          await editor.goto(`/role/view/${name}`);
          await expect(editor.locator('#page-content h2').first()).toContainText(name);
          for (const action of pageActions(editor)) {
            await expect(action, `${name}: nothing that changes it`).toHaveCount(0);
          }

          const response = await editor.goto(`/role/update/${name}`);
          expect(response?.status(), `${name}: the Edit Role page`).toBe(403);
          await expect(editor.locator('body')).toContainText(CHANGE_REFUSED);
        }

        // The server refuses forged forms too, and changes nothing.
        await editor.goto(`/role/view/${locked}`);
        const saved = await postWithPageCsrf(editor, `/role/update/${locked}`, {
          'RoleForm[description]': 'changed by the role editor',
          'RoleForm[permissions][]': 'job.view',
        });
        expect(saved.status(), `POST /role/update/${locked}`).toBe(403);
        for (const name of [locked, 'admin']) {
          const deleted = await postWithPageCsrf(editor, `/role/delete/${name}`);
          expect(deleted.status(), `POST /role/delete/${name}`).toBe(403);
        }

        // Its own role is the role editor's to change.
        await editor.goto('/role/view/e2e-role-manager');
        await expect(pageActions(editor)[0]).toHaveCount(1);
      });

      await page.goto(`/role/view/${locked}`);
      await expect(page.locator('#page-content')).toContainText('job.launch');
      await expect(page.locator('#page-content')).not.toContainText('changed by the role editor');
    } finally {
      await deleteRoleIfPresent(page, locked);
    }
  });

  test('regression: the New Role form disables the permissions the role editor lacks and refuses one sent anyway', async ({ browser, page }, testInfo) => {
    const forged = `e2e-rm-forged-${Date.now().toString(36)}`;
    try {
      await asUser(browser, testInfo, E2E_ROLE_EDITOR, async (editor) => {
        await editor.goto('/role/create');
        await expect(editor.locator('#role-form .role-permissions-hint')).toHaveText(NOT_HELD_HINT);
        for (const permission of NOT_HELD) {
          await expect(checkbox(editor, permission), `${permission}: disabled`).toBeDisabled();
        }
        for (const permission of HELD) {
          await expect(checkbox(editor, permission), `${permission}: enabled`).toBeEnabled();
        }

        // The toggle of the Users group ticks none of its disabled permissions.
        await editor.locator('#role-form .role-group-toggle[data-domain="user"]').click();
        for (const permission of ['user.view', 'user.update']) {
          await expect(checkbox(editor, permission)).not.toBeChecked();
        }

        // A permission sent anyway is refused, and nothing is created.
        await fillForm(editor, 'roleform', { name: forged, description: 'forged' });
        await checkbox(editor, 'role.view').check();
        await checkbox(editor, 'user.update').evaluate((box) => {
          (box as HTMLInputElement).disabled = false;
          (box as HTMLInputElement).checked = true;
        });
        await submitForm(editor);
        await expect(editor.locator('#role-form .text-danger')).toHaveText(permissionRefused('user.update'));
        await expect(checkbox(editor, 'user.update')).toBeDisabled();
      });

      const response = await page.goto(`/role/view/${forged}`);
      expect(response?.status(), 'the refused role was not created').toBe(404);
    } finally {
      await deleteRoleIfPresent(page, forged);
    }
  });

  test('the role editor creates, changes and deletes a role with permissions it holds', async ({ browser, page }, testInfo) => {
    const name = `e2e-rm-role-${Date.now().toString(36)}`;
    try {
      await asUser(browser, testInfo, E2E_ROLE_EDITOR, async (editor) => {
        await editor.goto('/role/create');
        await fillForm(editor, 'roleform', { name, description: 'Created by the role editor' });
        for (const permission of ['role.view', 'project.view']) {
          await checkbox(editor, permission).check();
        }
        await submitForm(editor);
        await expect(editor).toHaveURL(new RegExp(`/role/view/${name}$`));
        await expectFlash(editor, 'success', `Role "${name}" created.`);
        await expect(editor.locator('#page-content')).toContainText('role.view');
        await expect(editor.locator('#page-content')).toContainText('project.view');

        await pageActions(editor)[0].click();
        await expect(editor).toHaveURL(new RegExp(`/role/update/${name}$`));
        await checkbox(editor, 'job.view').check();
        await checkbox(editor, 'project.view').uncheck();
        await submitForm(editor);
        await expectFlash(editor, 'success', `Role "${name}" updated.`);
        await expect(editor.locator('#page-content')).toContainText('job.view');
        await expect(editor.locator('#page-content')).not.toContainText('project.view');

        editor.once('dialog', (dialog) => dialog.accept());
        await editor.locator('#page-content form[action*="/role/delete"] button[type="submit"]').click();
        await expectFlash(editor, 'success', `Role "${name}" deleted.`);
      });
    } finally {
      await deleteRoleIfPresent(page, name);
    }
  });
});

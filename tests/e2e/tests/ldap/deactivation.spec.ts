import { test, expect, Page } from '@playwright/test';
import { userId } from '../../lib/helpers';

/**
 * regression (CLAUDE.md security rule 15): an LDAP account an admin
 * deactivated in Ansilume was activated again by the user's next LDAP
 * sign-in or by the next ldap/sync that found them. Only an account that
 * ldap/sync deactivated because the directory no longer had the user
 * (user.ldap_sync_disabled) comes back now, and every other change of the
 * status clears that mark. The user page says under the status which of the
 * two an inactive LDAP account is.
 *
 * The E2E stack has no directory, so the sign-in and ldap/sync themselves
 * are proven by PHPUnit, seen failing on the code before the fix:
 * tests/integration/models/LoginFormInactiveAccountTest.php and
 * tests/integration/commands/LdapSyncLeavesAnsilumeDeactivationTest.php
 * (testRegression*); the status toggle clearing the mark by
 * tests/integration/controllers/UserLdapSyncMarkTest.php.
 *
 * Fixtures (commands/E2eLdapStatusSeeder.php), restored by every seed:
 * e2e-dir-gone, deactivated by ldap/sync; e2e-dir-locked, deactivated in
 * Ansilume. The last test activates e2e-dir-gone and deactivates it again,
 * which clears its mark: seed again before the spec runs again.
 */

/** app\controllers\UserController::DEACTIVATED_BY_LDAP_SYNC */
const DEACTIVATED_BY_LDAP_SYNC = 'Deactivated by ldap/sync because the directory no longer had this user: an LDAP sign-in, or an ldap/sync that finds them in the directory, activates the account again.';

/** app\controllers\UserController::DEACTIVATED_IN_ANSILUME */
const DEACTIVATED_IN_ANSILUME = 'Deactivated in Ansilume: the directory does not activate the account again, neither an LDAP sign-in nor ldap/sync.';

/** commands/E2eLdapStatusSeeder.php SYNC_DISABLED */
const SYNC_DISABLED = 'e2e-dir-gone';

/** commands/E2eLdapStatusSeeder.php ANSILUME_DISABLED */
const ANSILUME_DISABLED = 'e2e-dir-locked';

async function openUserPage(page: Page, username: string): Promise<void> {
  const id = await userId(page, username);
  await page.goto(`/user/view?id=${id}`);
  await expect(page.locator('h2').first()).toHaveText(username);
}

function statusBadge(page: Page) {
  return page.locator('dt:has-text("Status") + dd .badge');
}

function statusNote(page: Page) {
  return page.locator('#user-status-note');
}

test.describe('LDAP deactivation', () => {
  test('an account ldap/sync deactivated says that the directory activates it again', async ({ page }) => {
    await openUserPage(page, SYNC_DISABLED);

    await expect(statusBadge(page)).toHaveText('Inactive');
    await expect(statusNote(page)).toHaveText(DEACTIVATED_BY_LDAP_SYNC);
  });

  test('an account deactivated in Ansilume says that the directory does not', async ({ page }) => {
    await openUserPage(page, ANSILUME_DISABLED);

    await expect(statusBadge(page)).toHaveText('Inactive');
    await expect(statusNote(page)).toHaveText(DEACTIVATED_IN_ANSILUME);
  });

  test('Activate removes the note, and Deactivate shows that the directory no longer activates the account', async ({ page }) => {
    await openUserPage(page, SYNC_DISABLED);
    page.on('dialog', (dialog) => dialog.accept());

    await page.getByRole('button', { name: 'Activate', exact: true }).click();
    await expect(page.locator('.alert-success')).toContainText(`User "${SYNC_DISABLED}" activated.`);
    await expect(statusBadge(page)).toHaveText('Active');
    await expect(statusNote(page)).toHaveCount(0);

    await page.getByRole('button', { name: 'Deactivate', exact: true }).click();
    await expect(page.locator('.alert-success')).toContainText(`User "${SYNC_DISABLED}" deactivated.`);
    await expect(statusBadge(page)).toHaveText('Inactive');
    await expect(statusNote(page)).toHaveText(DEACTIVATED_IN_ANSILUME);
  });
});

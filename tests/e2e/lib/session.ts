import { Browser, Page, TestInfo } from '@playwright/test';
import { LOGIN_PASSWORD, LOGIN_SUBMIT, LOGIN_USERNAME } from './selectors';

/** Sign-in data of a seeded E2E user. Test fixtures, not secrets. */
export interface E2eUser {
  username: string;
  password: string;
}

/** commands/E2eController.php (also in auth.setup.ts). */
export const E2E_ADMIN: E2eUser = { username: 'e2e-admin', password: 'E2eAdminPass1!' };

/**
 * commands/E2eController.php (also in auth.setup.ts): the second approver of
 * e2e-approval-rule, who decides on what e2e-admin launched.
 */
export const E2E_OPERATOR: E2eUser = { username: 'e2e-operator', password: 'E2eOperatorPass1!' };

/**
 * The watcher of waiting runs: a plain viewer member of e2e-team-beta
 * (commands/E2eController.php, E2eTeamScopingSeeder.php), the user of the
 * viewer project's saved session. They see the run of e2e-beta-approval-wf
 * and its request, named and linked, but may decide nothing
 * (commands/E2eWorkflowWaitSeeder.php).
 */
export const E2E_WATCHER: E2eUser = { username: 'e2e-viewer', password: 'E2eViewerPass1!' };

/** commands/E2eCancelOnlyUserSeeder.php: may view and cancel jobs, not launch them. */
export const E2E_CANCELER: E2eUser = { username: 'e2e-canceler', password: 'E2eCancelerPass1!' };

/**
 * commands/E2eUserManagerSeeder.php HELP_DESK: holds only e2e-user-manager
 * (user.view, user.update, user.delete), so it may change only users who are
 * no superadmins and all of whose roles it may assign
 * (app\services\UserChangePolicy): viewers, not e2e-admin or an operator.
 */
export const E2E_HELP_DESK: E2eUser = { username: 'e2e-help-desk', password: 'E2eHelpDeskPass1!' };

/**
 * commands/E2eRoleManagerSeeder.php USERNAME: holds only e2e-role-manager
 * (role.view, role.create, role.update, role.delete) and the default role
 * viewer, so it creates roles only with permissions it holds and changes or
 * deletes only roles it may assign (app\services\RoleChangePolicy): its own
 * and viewer, not admin or operator.
 */
export const E2E_ROLE_EDITOR: E2eUser = { username: 'e2e-role-editor', password: 'E2eRoleEditorPass1!' };

/**
 * commands/E2eAdminRoleSeeder.php USERNAME: holds only e2e-allperm-role,
 * which holds every permission of the admin role as its own but not the
 * admin role, so it neither assigns the admin role nor changes a user who
 * holds it or the role itself (app\services\RoleGrantPolicy).
 */
export const E2E_ALLPERM_USER: E2eUser = { username: 'e2e-allperm-user', password: 'E2eAllpermUserPass1!' };

/**
 * commands/E2eApprovalSeeder.php LAUNCHER: an operator in the team of
 * e2e-template's project who approves on no rule, so both approvers of
 * e2e-dual-approval-rule decide on what this user launches.
 */
export const E2E_APPROVAL_LAUNCHER: E2eUser = { username: 'e2e-approval-launcher', password: 'E2eApprovalLauncherPass1!' };

/**
 * Runs `use` with a page signed in as `user` through the login form, in a
 * browser context of its own: for the unauthenticated project, which has no
 * saved session, and for users auth.setup.ts saves no session for. The
 * context starts without cookies: browser.newContext() would otherwise take
 * the project's saved session, and the login page sends signed-in users away.
 */
export async function asUser<T>(browser: Browser, testInfo: TestInfo, user: E2eUser, use: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: { cookies: [], origins: [] },
  });
  try {
    const page = await context.newPage();
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill(user.username);
    await page.locator(LOGIN_PASSWORD).fill(user.password);
    await Promise.all([
      page.waitForURL((url) => !url.pathname.startsWith('/site/login'), { timeout: 10_000 }),
      page.locator(LOGIN_SUBMIT).click(),
    ]);
    return await use(page);
  } finally {
    await context.close();
  }
}

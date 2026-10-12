import { Browser, Page, TestInfo } from '@playwright/test';
import { LOGIN_PASSWORD, LOGIN_SUBMIT, LOGIN_USERNAME } from './selectors';

/** Sign-in data of a seeded E2E user. Test fixtures, not secrets. */
export interface E2eUser {
  username: string;
  password: string;
}

/** commands/E2eController.php (also in auth.setup.ts). */
export const E2E_ADMIN: E2eUser = { username: 'e2e-admin', password: 'E2eAdminPass1!' };

/** commands/E2eCancelOnlyUserSeeder.php: may view and cancel jobs, not launch them. */
export const E2E_CANCELER: E2eUser = { username: 'e2e-canceler', password: 'E2eCancelerPass1!' };

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

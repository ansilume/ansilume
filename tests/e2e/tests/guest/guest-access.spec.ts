import { test, expect, Page } from '@playwright/test';
import { LOGIN_USERNAME } from '../../lib/selectors';
import { asUser, E2E_ADMIN } from '../../lib/session';

/**
 * A visitor who has not signed in reaches the public pages only. This spec
 * runs once, in the unauthenticated project, without a session.
 *
 * regression: signed-out visitors were treated as viewers. config/web.php
 * gives every user the default role viewer, and yii\rbac\DbManager applied it
 * to the user ID null of a visitor who had not signed in, so these pages
 * answered 200 with their content: credential names, types and owners,
 * runner groups, approval rules and usernames. app\components\AuthManager
 * gives the default role to signed-in users only, and
 * tests/integration/controllers/GuestAccessTest.php checks every action of
 * every web controller.
 */

/** Pages that answered a signed-out visitor with their content. */
const INDEX_PAGES = [
  '/credential/index',
  '/runner-group/index',
  '/approval-rule/index',
  '/analytics/index',
  '/project/index',
  '/inventory/index',
  '/job-template/index',
  '/notification-template/index',
  '/job/index',
  '/approval/index',
  '/workflow-template/index',
  '/workflow-job/index',
];

/** Seeded by commands/E2eController.php; its page showed its name, type and owner to anyone. */
const SEEDED_CREDENTIAL = 'e2e-credential';

/** The prefix of every seeded record; none may reach a signed-out visitor. */
const SEEDED_PREFIX = 'e2e-';

/**
 * A signed-out visitor who opens `path` is sent to the login page: the
 * server answers with a redirect to /login that carries none of the page's
 * content, and the browser lands on the login form.
 */
async function expectSentToTheLoginPage(page: Page, path: string, notShown: string): Promise<void> {
  const response = await page.request.get(path, { maxRedirects: 0 });
  expect(response.status(), `${path} answers with a redirect`).toBe(302);
  const location = response.headers()['location'] ?? '';
  expect(new URL(location, 'http://location.invalid').pathname, `${path} redirects to the login page`).toBe('/login');
  expect(await response.text(), `${path} sends none of its content`).not.toContain(notShown);

  await page.goto(path);
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.locator(LOGIN_USERNAME)).toBeVisible();
  await expect(page.locator('#page-content')).not.toContainText(notShown);
}

/** The path of a credential's page, found on the credential list (newest first, 20 a page). */
async function credentialPath(admin: Page, name: string): Promise<string> {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await admin.goto(`/credential/index?page=${listPage}`);
    const link = admin.locator('#page-content table').getByRole('link', { name, exact: true });
    if ((await link.count()) > 0) {
      const href = (await link.first().getAttribute('href')) ?? '';
      expect(href).toMatch(/^\/credential\/view\?id=\d+$/);
      return href;
    }
  }
  throw new Error(`The credential list shows no ${name}: run php yii e2e/seed.`);
}

test.describe('Signed-out visitors', () => {
  for (const path of INDEX_PAGES) {
    test(`regression: ${path} sends a signed-out visitor to the login page`, async ({ page }) => {
      await expectSentToTheLoginPage(page, path, SEEDED_PREFIX);
    });
  }

  test('regression: the page of a seeded credential sends a signed-out visitor to the login page', async ({ page, browser }, testInfo) => {
    const path = await asUser(browser, testInfo, E2E_ADMIN, (admin) => credentialPath(admin, SEEDED_CREDENTIAL));

    await expectSentToTheLoginPage(page, path, SEEDED_CREDENTIAL);
  });

  test('the login page shows a signed-out visitor no navigation', async ({ page }) => {
    const response = await page.goto('/login');
    expect(response?.status()).toBe(200);
    await expect(page.locator(LOGIN_USERNAME)).toBeVisible();

    await expect(page.locator('#sidebar nav')).toHaveCount(0);
    await expect(page.locator('#sidebar .nav-link')).toHaveCount(0);
    for (const path of INDEX_PAGES) {
      await expect(page.locator(`a[href="${path}"]`), `no link to ${path}`).toHaveCount(0);
    }
  });
});

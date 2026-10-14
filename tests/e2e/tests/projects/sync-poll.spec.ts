import { test, expect, APIRequestContext, Page } from '@playwright/test';
import { LOGIN_PASSWORD, LOGIN_SUBMIT, LOGIN_USERNAME } from '../../lib/selectors';
import { E2E_ADMIN, asUser } from '../../lib/session';

/**
 * The sync output of a project's page (views/project/view.php) polls
 * /project/sync-status once when the page loads, then every two seconds
 * while the project syncs. The poll asks for JSON, and a refused poll (401
 * or 403: signed out, or no access any more; 404: the project is gone)
 * stops polling: a note under the sync output asks for a reload.
 *
 * The project is e2e-project, which /e2e/seed-syncing-project
 * (controllers/E2eController.php) puts into Syncing with three rows of
 * output; nothing syncs it. Each test resets it.
 */

/** controllers/ProjectController.php SYNC_LIVE_STOPPED */
const SYNC_LIVE_STOPPED = 'Live updates stopped. Reload the page to keep following the sync.';

const PROJECT = 'e2e-project';

/** Puts the project into Syncing; its ID. */
async function seedSyncing(request: APIRequestContext): Promise<number> {
  const seeded = await request.post('/e2e/seed-syncing-project', { params: { name: PROJECT } });
  expect(seeded.status()).toBe(200);
  return ((await seeded.json()) as { project_id: number }).project_id;
}

function isPollOf(projectId: number, url: string): boolean {
  return url.includes(`/project/sync-status?id=${projectId}&since=`);
}

/**
 * Opens the page of the syncing project with every poll answered with
 * `status`; the number of polls so far.
 */
async function openPolledProject(page: Page, projectId: number, status: number): Promise<() => number> {
  let polls = 0;
  await page.route((url) => isPollOf(projectId, url.toString()), async (route) => {
    polls++;
    await route.fulfill({ status, json: { name: 'Refused', message: 'Refused by the test', code: 0, status } });
  });
  await page.goto(`/project/view?id=${projectId}`);
  return () => polls;
}

test.describe('Project sync output poll', () => {
  let projectId = 0;

  test.beforeEach(async ({ request }) => {
    projectId = await seedSyncing(request);
  });

  test.afterEach(async ({ request }) => {
    await request.post('/e2e/seed-syncing-project', { params: { name: PROJECT, reset: 1 } });
  });

  // regression: a refused poll was sent again every two seconds for as long
  // as the page stayed open: the script read every answer as JSON and kept
  // its interval (git show HEAD:views/project/view.php,
  // `.then(function (r) { return r.json(); })`, no stop).
  test('regression: a refused poll of the sync output was sent again every two seconds', async ({ page }) => {
    const calls = await openPolledProject(page, projectId, 403);

    await expect(page.locator('#sync-live-stopped')).toHaveText(SYNC_LIVE_STOPPED);
    await page.waitForTimeout(5_000);
    expect(calls(), 'one poll, then none').toBe(1);
    // The output stays as the page showed it.
    await expect(page.locator('#sync-log-output')).toContainText('Cloning into');
  });

  for (const status of [401, 404]) {
    test(`a poll answered ${status} stops polling too`, async ({ page }) => {
      const calls = await openPolledProject(page, projectId, status);

      await expect(page.locator('#sync-live-stopped')).toHaveText(SYNC_LIVE_STOPPED);
      await page.waitForTimeout(4_500);
      expect(calls(), 'one poll, then none').toBe(1);
    });
  }

  test('a poll that fails is tried again, without the note', async ({ page }) => {
    const calls = await openPolledProject(page, projectId, 500);

    await expect.poll(calls, { message: 'the poll two seconds after the failed one', timeout: 6_000 }).toBeGreaterThanOrEqual(2);
    await expect(page.locator('#sync-live-stopped')).toHaveCount(0);
  });

  test('the poll asks for JSON and shows the output it gets', async ({ page }) => {
    const polled = page.waitForRequest((request) => isPollOf(projectId, request.url()));
    await page.goto(`/project/view?id=${projectId}`);

    expect((await polled).headers()['accept']).toBe('application/json');
    await expect(page.locator('#sync-worker-indicator')).toBeVisible();
    await expect(page.locator('#sync-log-output')).toContainText('Resolving deltas: 100%');
    await expect(page.locator('#sync-live-stopped')).toHaveCount(0);
  });

  // regression: after a sign-out, signing in again led to the sync poll's
  // JSON. The signed-out poll asked for anything (fetch's Accept: */*), so
  // the server sent it to the login page and kept the poll's address to
  // return to after the next sign-in. In a session of its own, so no other
  // spec's session ends.
  test('regression: after a sign-out, signing in again led to the sync poll\'s JSON', async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (page) => {
      const answered = page.waitForResponse((response) => isPollOf(projectId, response.url()) && response.status() === 200);
      await page.goto(`/project/view?id=${projectId}`);
      await answered;

      // Signed out in another tab: the session is gone. A poll sent before
      // that, still signed in, is answered 200: the next one counts.
      await page.context().clearCookies();
      const refused = await page.waitForResponse((response) => isPollOf(projectId, response.url()) && response.status() !== 200, { timeout: 10_000 });
      expect(refused.status(), 'the poll of a signed-out page is refused, not sent to the login page').toBe(403);
      await expect(page.locator('#sync-live-stopped')).toHaveText(SYNC_LIVE_STOPPED);

      await page.goto('/site/login');
      await page.locator(LOGIN_USERNAME).fill(E2E_ADMIN.username);
      await page.locator(LOGIN_PASSWORD).fill(E2E_ADMIN.password);
      await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/site/login'), { timeout: 10_000 }),
        page.locator(LOGIN_SUBMIT).click(),
      ]);
      expect(new URL(page.url()).pathname, 'signed in again, the dashboard').toBe('/');
    });
  });
});

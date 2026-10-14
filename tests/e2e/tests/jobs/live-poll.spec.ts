import { test, expect, Locator, Page } from '@playwright/test';
import { LOGSTREAM_RUNNING_JOB, seededJobId } from '../../lib/jobs';
import { LIVE_STOPPED_JOB, setPageVisibility } from '../../lib/runs';
import { LOGIN_PASSWORD, LOGIN_SUBMIT, LOGIN_USERNAME } from '../../lib/selectors';
import { E2E_ADMIN, asUser } from '../../lib/session';

/**
 * The job page's poll (views/job/view.php, /job/log-poll). The page of a job
 * that has not finished polls 1.5 seconds after it loaded. While the job
 * runs, it polls again 2.5 seconds after each answer. While the job waits
 * (for an approval, to be queued or for a runner: the status the answer
 * gives), an answer that brings nothing new doubles the wait, up to 30
 * seconds, and one that changed something brings back 2.5 seconds; a poll
 * that failed doubles the wait as well. While the page is hidden it does
 * not poll; shown again, it polls at once. A refused poll (401 or 403:
 * signed out, or no access any more; 404: the job is gone) stops polling:
 * the Live badge goes, and a note under the title asks for a reload.
 *
 * The page is that of the seeded running job e2e-logstream-running
 * (commands/E2eLogStreamSeeder.php), with its polls answered by the test;
 * nothing changes on the server.
 */

/** A poll answer: the JSON object /job/log-poll answers. */
type Answer = {
  status: string;
  chunks: { sequence: number; stream: string; content: string }[];
  finished: boolean;
  execution_command: string | null;
};

/** What a poll gets: an answer, an error status (with a JSON body), or the server's answer (null). */
type Reply = Answer | number | null;

/** Job::statusLabel() of the statuses the answers give. */
const AWAITING_APPROVAL = 'Awaiting Approval';
const QUEUED = 'Queued';
const RUNNING = 'Running';

/** An answer that gives the job's status `status` and nothing new: no output, no execution command. */
function nothingNew(status: string): Answer {
  return { status, chunks: [], finished: false, execution_command: null };
}

/**
 * Opens the page of the seeded running job with each poll answered as
 * `reply(poll)` says (1 for the first poll). The number of polls so far, by
 * calls(), and the time of each (Date.now() of the test), by times().
 */
async function openJob(page: Page, reply: (poll: number) => Reply): Promise<{ calls: () => number; times: () => number[] }> {
  const jobId = await seededJobId(page, LOGSTREAM_RUNNING_JOB);
  const times: number[] = [];
  await page.route(new RegExp(`/job/log-poll\\?id=${jobId}&after=`), async (route) => {
    times.push(Date.now());
    const answer = reply(times.length);
    if (answer === null) {
      await route.continue();
    } else if (typeof answer === 'number') {
      await route.fulfill({ status: answer, json: { name: 'Error', message: `Answered ${answer} by the test`, code: 0, status: answer } });
    } else {
      await route.fulfill({ json: answer });
    }
  });
  await page.goto(`/job/view?id=${jobId}`);
  return { calls: () => times.length, times: () => [...times] };
}

/**
 * Opens the page of the seeded running job with every poll answered with
 * the error `status`; the number of polls so far.
 */
async function openPolledJob(page: Page, status: number): Promise<() => number> {
  return (await openJob(page, () => status)).calls;
}

/** The status badge of the open job page, which each answer writes. */
function statusBadge(page: Page): Locator {
  return page.locator('#status-badge');
}

/** Empties the status badge: it shows a status again once the page has shown the next answer. */
async function blankBadge(page: Page): Promise<void> {
  await statusBadge(page).evaluate((badge) => {
    badge.textContent = '';
  });
}

/**
 * With the page's clock paused (page.clock): moves it on until the next
 * poll, which must come `ms` after the last answer (half a second either
 * way), and waits until the page has shown that poll's answer, whose status
 * reads `label`: it then waits for the poll after it.
 */
async function expectPollAfter(page: Page, calls: () => number, ms: number, label: string): Promise<void> {
  const before = calls();
  await blankBadge(page);
  await page.clock.runFor(ms - 500);
  await page.waitForTimeout(300);
  expect(calls(), `no poll sooner than ${ms} ms after the last answer`).toBe(before);
  await page.clock.runFor(1_000);
  await expect.poll(calls, { message: `a poll ${ms} ms after the last answer` }).toBe(before + 1);
  await expect(statusBadge(page), 'the page shows the answer').toHaveText(label);
}

test.describe('Job page poll', () => {
  // regression: a refused poll of the job page was sent again every five
  // seconds for as long as the page stayed open: the script read every
  // answer as JSON, and the error it met on a refusal sent it to its retry
  // (git show HEAD:views/job/view.php, `.catch(function () { setTimeout(poll, 5000); })`).
  test('regression: a refused poll of the job page was sent again every five seconds', async ({ page }) => {
    const calls = await openPolledJob(page, 403);

    await expect(page.locator('#live-stopped')).toHaveText(LIVE_STOPPED_JOB);
    await expect(page.locator('#live-indicator')).toHaveCount(0);
    await page.waitForTimeout(7_000);
    expect(calls(), 'one poll, then none').toBe(1);
    // The output stays as the page showed it.
    await expect(page.locator('#job-log')).toContainText('PLAY [webservers]');
  });

  for (const status of [401, 404]) {
    test(`a poll answered ${status} stops polling too`, async ({ page }) => {
      const calls = await openPolledJob(page, status);

      await expect(page.locator('#live-stopped')).toHaveText(LIVE_STOPPED_JOB);
      await expect(page.locator('#live-indicator')).toHaveCount(0);
      await page.waitForTimeout(6_000);
      expect(calls(), 'one poll, then none').toBe(1);
    });
  }

  test('a poll that fails is tried again, twice as late each time, and the page stays live', async ({ page }) => {
    // Three polls take 16.5 seconds.
    test.slow();
    const { calls, times } = await openJob(page, () => 500);

    // A failed poll counts as an answer that changed nothing: 5, then 10 seconds later.
    await expect.poll(calls, { message: 'two polls after the failed one', timeout: 20_000 }).toBe(3);
    const [first, second, third] = times();
    expect(second - first, 'the poll after the first failed one').toBeGreaterThanOrEqual(4_500);
    expect(second - first, 'the poll after the first failed one').toBeLessThan(7_000);
    expect(third - second, 'the poll after the second failed one').toBeGreaterThanOrEqual(9_500);
    expect(third - second, 'the poll after the second failed one').toBeLessThan(12_000);
    await expect(page.locator('#live-indicator')).toBeVisible();
    await expect(page.locator('#live-stopped')).toHaveCount(0);
  });

  // regression: a hidden job page kept polling every 2.5 seconds, also for
  // the days a job may wait for an approval: the script scheduled each poll
  // whether or not the page was hidden (git show HEAD:views/job/view.php: no
  // document.hidden, no visibilitychange; `setTimeout(poll, 2500)`).
  test('regression: a hidden job page kept polling', async ({ page }) => {
    const { calls } = await openJob(page, () => nothingNew('running'));
    await expect.poll(calls, { message: 'the poll 1.5 seconds after the page loaded' }).toBe(1);

    await setPageVisibility(page, 'hidden');
    await page.waitForTimeout(10_000);
    expect(calls(), 'no poll while the page is hidden').toBe(1);
    await expect(page.locator('#live-indicator')).toBeVisible();

    // Shown again: a poll at once, and the next 2.5 seconds later (the job runs).
    await setPageVisibility(page, 'visible');
    await expect.poll(calls, { message: 'a poll as soon as the page is shown', timeout: 1_000 }).toBe(2);
    await expect.poll(calls, { message: 'the next poll 2.5 seconds later', timeout: 4_000 }).toBe(3);
  });

  // regression: the page of a waiting job polled every 2.5 seconds for as
  // long as the job waited, which can be days for an approval (git show
  // HEAD:views/job/view.php: `setTimeout(poll, 2500)` after every answer).
  // The page's clock is paused after the first two polls and moved on by
  // hand; the answers give the job's status and nothing new.
  test('regression: a waiting job\'s page polled every 2.5 seconds while nothing changed', async ({ page }) => {
    await page.clock.install();
    let answer = nothingNew('pending_approval');
    const { calls } = await openJob(page, () => answer);
    // The page was rendered with the job running: the badge shows the first answer.
    await expect(statusBadge(page)).toHaveText(AWAITING_APPROVAL);
    expect(calls(), 'the poll 1.5 seconds after the page loaded').toBe(1);
    // Jumps a minute ahead: the second poll, 2.5 seconds after the first answer, comes at once.
    await blankBadge(page);
    await page.clock.pauseAt(Date.now() + 60_000);
    await expect.poll(calls).toBe(2);
    await expect(statusBadge(page)).toHaveText(AWAITING_APPROVAL);

    // Nothing new while the job waits: twice as long each time, at most 30 seconds.
    for (const ms of [5_000, 10_000, 20_000, 30_000, 30_000]) {
      await expectPollAfter(page, calls, ms, AWAITING_APPROVAL);
    }

    // Hidden: no poll, however long; shown again: a poll at once, then 2.5 and 5 seconds later.
    await setPageVisibility(page, 'hidden');
    const hiddenAfter = calls();
    await page.clock.runFor(120_000);
    await page.waitForTimeout(300);
    expect(calls(), 'no poll while the page is hidden').toBe(hiddenAfter);
    await blankBadge(page);
    await setPageVisibility(page, 'visible');
    await expect.poll(calls, { message: 'a poll as soon as the page is shown' }).toBe(hiddenAfter + 1);
    await expect(statusBadge(page)).toHaveText(AWAITING_APPROVAL);
    await expectPollAfter(page, calls, 2_500, AWAITING_APPROVAL);
    await expectPollAfter(page, calls, 5_000, AWAITING_APPROVAL);

    // A change (approved, the job is queued): 2.5 seconds again, then twice as long.
    answer = nothingNew('queued');
    await expectPollAfter(page, calls, 10_000, QUEUED);
    await expectPollAfter(page, calls, 2_500, QUEUED);
    await expectPollAfter(page, calls, 5_000, QUEUED);

    // The job runs: every 2.5 seconds, though the answers bring nothing new.
    answer = nothingNew('running');
    await expectPollAfter(page, calls, 10_000, RUNNING);
    await expectPollAfter(page, calls, 2_500, RUNNING);
    await expectPollAfter(page, calls, 2_500, RUNNING);
  });

  // regression: after a sign-out, signing in again led to the poll's JSON.
  // The signed-out poll asked for anything (fetch's Accept: */*), so the
  // server sent it to the login page and kept the poll's address to return
  // to after the next sign-in; the script then polled on every five seconds.
  // Signed in again, the user landed on /job/log-poll instead of the
  // dashboard. In a session of its own, so no other spec's session ends.
  test('regression: after a sign-out, signing in again led to the poll\'s JSON', async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (page) => {
      const jobId = await seededJobId(page, LOGSTREAM_RUNNING_JOB);
      const isPoll = (url: string) => url.includes(`/job/log-poll?id=${jobId}&after=`);
      const answered = page.waitForResponse((response) => isPoll(response.url()) && response.status() === 200);
      await page.goto(`/job/view?id=${jobId}`);
      await answered;

      // Signed out in another tab: the session is gone.
      await page.context().clearCookies();
      const refused = await page.waitForResponse((response) => isPoll(response.url()), { timeout: 10_000 });
      expect(refused.status(), 'the poll of a signed-out page is refused, not sent to the login page').toBe(403);
      await expect(page.locator('#live-stopped')).toHaveText(LIVE_STOPPED_JOB);

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

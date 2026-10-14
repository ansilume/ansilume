import { test, expect, Page } from '@playwright/test';
import {
  CANCELED,
  LIVE_STOPPED_RUN,
  MIXED_WORKFLOW,
  PAUSED,
  STEP_RUN_CANCELED,
  bannerFacts,
  bannerPaused,
  bannerPersonal,
  bannerRequest,
  bannerTitle,
  bannerWarning,
  expectNotReloaded,
  markPage,
  onlyRunId,
  reviewLink,
  runState,
  setPageVisibility,
  stepBadge,
  waitBanner,
} from '../../lib/runs';

/**
 * The run page's live client (web/js/workflow-run-live.js), driven by poll
 * answers the test writes: the run page polls /workflow-job/status and
 * updates itself from the answer, never by loading the page again. It polls
 * once when the page has loaded, then three seconds after an answer that
 * changed something and twice as long as the last time (at most 30 seconds)
 * after one that did not; not while the page is hidden; and never again once
 * a poll was refused (401, 403, 404). Nothing changes on the server.
 *
 * The page is that of the seeded run of e2e-mixed-gated-wf, which waits for
 * an approval (commands/E2eWorkflowWaitSeeder.php). e2e-admin, who launched
 * it, sees its Cancel form and, hidden while the run is not paused, its
 * Resume form.
 */

/** A poll answer: the JSON object /workflow-job/status answers. */
type Answer = Record<string, unknown>;

/** One step of a poll answer. */
type Step = Record<string, unknown>;

/**
 * Opens the run page of the seeded run with its poll answered by
 * `next(real, poll)`, which gets the real answer and the number of the poll
 * (1 for the first): the real answer while it returns null. The page is
 * marked (markPage()) once it has loaded; the real answer it polled first.
 * The number of polls so far, by calls().
 */
async function openStubbedRun(page: Page, next: (real: Answer, poll: number) => Answer | null): Promise<{ real: Answer; calls: () => number }> {
  const runId = await onlyRunId(page, MIXED_WORKFLOW);
  const real = (await (await page.request.get(`/workflow-job/status?id=${runId}`)).json()) as Answer;
  let polls = 0;
  await page.route(new RegExp(`/workflow-job/status\\?id=${runId}$`), async (route) => {
    polls++;
    const answer = next(real, polls);
    if (answer === null) {
      await route.continue();
      return;
    }
    await route.fulfill({ json: answer });
  });
  await page.goto(`/workflow-job/view?id=${runId}`);
  await expect(page.locator('#wj-live')).toBeVisible();
  await markPage(page);
  return { real, calls: () => polls };
}

/**
 * Opens the run page of the seeded run with every poll refused with
 * `status`; the number of polls so far.
 */
async function openRefusedRun(page: Page, status: number): Promise<() => number> {
  const runId = await onlyRunId(page, MIXED_WORKFLOW);
  let polls = 0;
  await page.route(new RegExp(`/workflow-job/status\\?id=${runId}$`), async (route) => {
    polls++;
    await route.fulfill({ status, json: { name: 'Refused', message: 'Refused by the test', code: 0, status } });
  });
  await page.goto(`/workflow-job/view?id=${runId}`);
  return () => polls;
}

/** The steps of a real poll answer. */
function stepsOf(answer: Answer): Step[] {
  return answer.steps as Step[];
}

/** The Duration cell of the first step row of the open run page. */
function firstDuration(page: Page) {
  return page.locator('#wj-steps-table tbody tr').first().locator('[data-wjs-duration-cell]');
}

/**
 * The real answer with the poll's number in the first step's duration
 * (`e2e-poll-N`), which the page shows but which changes nothing that tells
 * answers apart; with `title`, also a banner of that title, which does.
 */
function numbered(real: Answer, poll: number, title?: string): Answer {
  const steps = stepsOf(real).map((step, index) => (index === 0 ? { ...step, duration_label: `e2e-poll-${poll}` } : step));
  const banner = title === undefined ? real.banner : { ...(real.banner as Answer), title };
  return { ...real, banner, steps };
}

/**
 * With the page's clock paused (page.clock): moves it on until the next
 * poll, which must come `ms` after the last answer (half a second either
 * way), and waits until the page has shown that poll's answer (numbered()):
 * it then waits for the poll after it.
 */
async function expectPollAfter(page: Page, calls: () => number, ms: number): Promise<void> {
  const before = calls();
  await page.clock.runFor(ms - 500);
  await page.waitForTimeout(300);
  expect(calls(), `no poll sooner than ${ms} ms after the last answer`).toBe(before);
  await page.clock.runFor(1_000);
  await expect.poll(calls, { message: `a poll ${ms} ms after the last answer` }).toBe(before + 1);
  await expect(firstDuration(page)).toHaveText(`e2e-poll-${before + 1}`);
}

test.describe('Workflow run live client', () => {
  // regression: Resume appeared only after a reload. The page rendered the
  // Resume form only for a run paused when it loaded, and its script never
  // showed it (git show HEAD:views/workflow-job/view.php, $hasPausedStep).
  test('regression: Resume appeared only after a reload', async ({ page }) => {
    let answer: Answer | null = null;
    const { real } = await openStubbedRun(page, () => answer);
    await expect(page.locator('#wj-resume-form')).toBeHidden();
    await expect(page.locator('#wj-cancel-form')).toBeVisible();

    // The run reaches a pause step.
    answer = {
      ...real,
      state: 'paused',
      state_label: PAUSED,
      state_css: 'warning',
      waiting_for: 'resume',
      banner: { kind: 'resume', tone: 'warning', title: bannerPaused('e2e-stubbed-pause'), facts: [{ text: 'waiting since 12:00:00' }], personal: null, warning: null },
      actions: { resume: true, cancel: true },
      steps: stepsOf(real).map((step) => ({ ...step, state: 'paused', state_label: PAUSED, state_css: 'warning', waiting_for: 'resume', approval: null })),
    };

    await expect(page.locator('#wj-resume-form')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#wj-cancel-form')).toBeVisible();
    await expect(runState(page)).toHaveText(PAUSED);
    await expect(waitBanner(page)).toHaveAttribute('data-wait', 'resume');
    await expect(waitBanner(page)).toHaveClass('alert alert-warning');
    await expect(bannerTitle(page)).toHaveText(bannerPaused('e2e-stubbed-pause'));
    await expect(bannerFacts(page)).toHaveText(['waiting since 12:00:00']);
    await expect(bannerPersonal(page)).toHaveCount(0);
    await expect(stepBadge(page.locator('#wj-steps-table tbody tr').first())).toHaveText(PAUSED);
    // The request link goes with the approval the answer no longer names.
    await expect(page.locator('[data-wjs-request-link]')).toHaveCount(0);
    await expectNotReloaded(page);
  });

  // regression: a finished run kept its Cancel button until a reload. The
  // script only dropped the Live badge (git show
  // HEAD:views/workflow-job/view.php, `if (data.is_finished)`).
  test('regression: a finished run kept its Cancel button until a reload', async ({ page }) => {
    let answer: Answer | null = null;
    const { real, calls } = await openStubbedRun(page, () => answer);
    await expect(page.locator('#wj-cancel-form')).toBeVisible();
    await expect(waitBanner(page)).toBeVisible();

    // The run was canceled elsewhere.
    answer = {
      ...real,
      status: 'canceled',
      status_label: CANCELED,
      status_css: 'warning',
      state: 'canceled',
      state_label: CANCELED,
      state_css: 'warning',
      waiting_for: null,
      is_finished: true,
      finished_label: '12:34:56',
      banner: null,
      actions: { resume: false, cancel: false },
      steps: stepsOf(real).map((step) => ({
        ...step,
        is_current: false,
        status: 'failed',
        status_label: 'Failed',
        status_css: 'danger',
        state: 'canceled',
        state_label: CANCELED,
        state_css: 'warning',
        waiting_for: null,
        error_message: STEP_RUN_CANCELED,
        finished_label: '12:34:56',
      })),
    };

    await expect(page.locator('#wj-cancel-form')).toBeHidden({ timeout: 10_000 });
    await expect(page.locator('#wj-resume-form')).toBeHidden();
    await expect(waitBanner(page)).toBeHidden();
    await expect(page.locator('#wj-live')).toHaveCount(0);
    await expect(runState(page)).toHaveText(CANCELED);
    await expect(page.locator('#wj-finished')).toHaveText('12:34:56');
    const row = page.locator('#wj-steps-table tbody tr').first();
    await expect(stepBadge(row)).toHaveText(CANCELED);
    await expect(row.locator('[data-wjs-error]')).toHaveText(STEP_RUN_CANCELED);
    await expect(row).not.toHaveClass(/table-active/);

    // Polling stopped with the answer that said the run finished.
    const polled = calls();
    await page.waitForTimeout(4_000);
    expect(calls(), 'no poll after the run finished').toBe(polled);
    await expectNotReloaded(page);
  });

  // regression: the poll client inserted server text as HTML: the old script
  // built the badge of a step it added with innerHTML (git show
  // HEAD:views/workflow-job/view.php, appendRow()).
  test('regression: the poll client inserted server text as HTML', async ({ page }) => {
    const html = '<img src=x onerror="window.e2eInjected=1">';
    let answer: Answer | null = null;
    const { real } = await openStubbedRun(page, () => answer);
    const first = stepsOf(real)[0];

    answer = {
      ...real,
      state_label: `${html}state`,
      banner: {
        kind: 'approval',
        tone: 'info',
        title: `${html}title`,
        facts: [{ text: `${html}request`, request: true, href: '/approval/view?id=1' }, { text: `${html}fact` }],
        personal: { text: `${html}personal`, action: { text: `${html}review`, href: '/approval/view?id=1' } },
        warning: `${html}warning`,
      },
      steps: [
        first,
        {
          ...first,
          // A new execution: the page adds its row.
          id: Number(first.id) + 1000000,
          step_index: 2,
          step_name: `${html}name`,
          step_type_label: `${html}type`,
          // The badge shows state_label; the old script wrote status_label as HTML.
          state_label: `${html}badge`,
          status_label: `${html}badge`,
          error_message: `${html}error`,
          decision_note: `${html}note`,
          job_id: null,
          approval: { id: 1, label: `${html}label`, status: 'pending', status_label: 'Pending', url: null },
          started_label: `${html}started`,
          duration_label: `${html}duration`,
          finished_label: `${html}finished`,
          is_current: false,
        },
      ],
    };

    const added = page.locator('#wj-steps-table tbody tr').nth(1);
    await expect(added.locator('[data-wjs-name-cell]')).toHaveText(`${html}name`, { timeout: 10_000 });
    // Nothing ran: an image made from the text would have failed to load by now.
    await page.waitForTimeout(1_000);
    expect(await page.evaluate(() => (window as unknown as { e2eInjected?: number }).e2eInjected), 'no handler of the text ran').toBeUndefined();
    await expect(page.locator('img[src="x"]')).toHaveCount(0);
    // Shown as text, every field of it.
    await expect(added.locator('[data-wjs-type-cell]')).toHaveText(`${html}type`);
    await expect(stepBadge(added)).toHaveText(`${html}badge`);
    await expect(added.locator('[data-wjs-error]')).toHaveText(`${html}error`);
    await expect(added.locator('[data-wjs-decision]')).toHaveText(`${html}note`);
    await expect(added.locator('[data-wjs-request-link]')).toHaveText(`${html}label`);
    await expect(added.locator('[data-wjs-started-cell]')).toHaveText(`${html}started`);
    await expect(added.locator('[data-wjs-duration-cell]')).toHaveText(`${html}duration`);
    await expect(added.locator('[data-wjs-finished-cell]')).toHaveText(`${html}finished`);
    await expect(runState(page)).toHaveText(`${html}state`);
    await expect(bannerTitle(page)).toHaveText(`${html}title`);
    await expect(bannerFacts(page)).toHaveText([`${html}request`, `${html}fact`]);
    await expect(bannerPersonal(page)).toHaveText(`${html}personal`);
    await expect(reviewLink(page)).toHaveText(`${html}review`);
    await expect(bannerWarning(page)).toHaveText(`${html}warning`);
    await expect(page.locator('img[src="x"]')).toHaveCount(0);
    expect(await page.evaluate(() => (window as unknown as { e2eInjected?: number }).e2eInjected)).toBeUndefined();
    await expectNotReloaded(page);
  });

  test('addresses and classes the poll answers are used only when they are local and plain', async ({ page }) => {
    let answer: Answer | null = null;
    const { real } = await openStubbedRun(page, () => answer);

    answer = {
      ...real,
      state_label: 'e2e-unsafe-css',
      state_css: 'info x" onmouseover="alert(1)',
      banner: {
        kind: 'approval',
        tone: 'info bad',
        title: 'e2e-foreign-addresses',
        facts: [
          { text: 'Approval Request #1', request: true, href: 'https://evil.example/request' },
          { text: 'e2e-protocol-relative', href: '//evil.example/a' },
          { text: 'e2e-backslash', href: '/\\evil.example/b' },
          { text: 'e2e-script', href: 'javascript:alert(1)' },
          { text: 'e2e-local', href: '/approval/view?id=1' },
        ],
        personal: { text: 'e2e-personal', action: { text: 'Review & decide', href: '//evil.example/review' } },
        warning: null,
      },
      steps: stepsOf(real).map((step) => ({
        ...step,
        approval: { id: 1, label: 'Request #1', status: 'pending', status_label: 'Pending', url: 'https://evil.example/step' },
      })),
    };

    await expect(bannerTitle(page)).toHaveText('e2e-foreign-addresses', { timeout: 10_000 });
    // A tone or state colour that is no plain class name falls back to grey.
    await expect(waitBanner(page)).toHaveClass('alert alert-secondary');
    await expect(runState(page)).toHaveText('e2e-unsafe-css');
    await expect(runState(page)).toHaveClass('badge text-bg-secondary');
    // Only the local address is a link; the request is named, not linked.
    await expect(page.locator('a[href*="evil.example"], a[href^="javascript"]')).toHaveCount(0);
    await expect(waitBanner(page).locator('a')).toHaveCount(1);
    await expect(waitBanner(page).locator('a')).toHaveText('e2e-local');
    await expect(waitBanner(page).locator('a')).toHaveAttribute('href', '/approval/view?id=1');
    await expect(bannerRequest(page)).toHaveText('Approval Request #1');
    await expect(bannerRequest(page)).toHaveJSProperty('tagName', 'SPAN');
    await expect(bannerFacts(page)).toHaveText(['Approval Request #1', 'e2e-protocol-relative', 'e2e-backslash', 'e2e-script', 'e2e-local']);
    await expect(reviewLink(page)).toHaveCount(0);
    await expect(bannerPersonal(page)).toHaveText('e2e-personal');
    const request = page.locator('#wj-steps-table [data-wjs-request-link]');
    await expect(request).toHaveText('Request #1');
    await expect(request).toHaveJSProperty('tagName', 'SPAN');
    await expectNotReloaded(page);
  });

  // regression: a hidden page kept polling every three seconds for as long
  // as the run waited. The script scheduled each poll whether or not the
  // page was hidden (no document.hidden, no visibilitychange), and a timer
  // set after a fetch is not one that Chrome throttles in a hidden tab.
  test('regression: a hidden page kept polling', async ({ page }) => {
    const { calls } = await openStubbedRun(page, () => null);
    await expect.poll(calls, { message: 'the poll right after the page loaded' }).toBeGreaterThanOrEqual(1);

    await setPageVisibility(page, 'hidden');
    const hiddenAfter = calls();
    await page.waitForTimeout(10_000);
    expect(calls(), 'no poll while the page is hidden').toBe(hiddenAfter);
    await expect(page.locator('#wj-live')).toBeVisible();

    // Shown again: one poll at once, and the next three seconds later.
    await setPageVisibility(page, 'visible');
    await expect.poll(calls, { message: 'a poll as soon as the page is shown', timeout: 1_500 }).toBe(hiddenAfter + 1);
    await expect.poll(calls, { message: 'the next poll three seconds later', timeout: 4_500 }).toBe(hiddenAfter + 2);
    await expectNotReloaded(page);
  });

  // regression: a refused poll was sent again every three seconds for as
  // long as the page stayed open, and each refusal wrote an error with its
  // stack trace to the log. The script treated a 403 as an answer without
  // data (`response.ok ? response.json() : null`) and polled again.
  test('regression: a refused poll was sent again every three seconds', async ({ page }) => {
    const calls = await openRefusedRun(page, 403);

    await expect(page.locator('#wj-live-stopped')).toBeVisible();
    await expect(page.locator('#wj-live-stopped')).toHaveText(LIVE_STOPPED_RUN);
    await expect(page.locator('#wj-live')).toHaveCount(0);
    await page.waitForTimeout(7_000);
    expect(calls(), 'one poll, then none').toBe(1);
    // The page stays as it was rendered.
    await expect(waitBanner(page)).toBeVisible();
    await expect(page.locator('#wj-cancel-form')).toBeVisible();
  });

  for (const status of [401, 404]) {
    test(`a poll answered ${status} stops polling too`, async ({ page }) => {
      const calls = await openRefusedRun(page, status);

      await expect(page.locator('#wj-live-stopped')).toHaveText(LIVE_STOPPED_RUN);
      await expect(page.locator('#wj-live')).toHaveCount(0);
      await page.waitForTimeout(4_000);
      expect(calls(), 'one poll, then none').toBe(1);
    });
  }

  test('a poll that fails is tried again later, and the page stays live', async ({ page }) => {
    const calls = await openRefusedRun(page, 500);

    // Six seconds later: a failed poll counts as an answer that changed nothing.
    await expect.poll(calls, { message: 'the poll after the failed one', timeout: 9_000 }).toBe(2);
    await expect(page.locator('#wj-live')).toBeVisible();
    await expect(page.locator('#wj-live-stopped')).toBeHidden();
  });

  // regression: unchanged answers were polled every three seconds, also
  // during waits that last days (INTERVAL was the only pace of the script).
  // The page's clock is paused after the first two polls and moved on by
  // hand; each answer is the real one, numbered in a field that tells no
  // answers apart.
  test('regression: unchanged answers were polled every three seconds', async ({ page }) => {
    await page.clock.install();
    let changedFrom = Number.POSITIVE_INFINITY;
    const { calls } = await openStubbedRun(page, (real, poll) => numbered(real, poll, poll >= changedFrom ? 'e2e-changed' : undefined));
    await expect(firstDuration(page)).toHaveText('e2e-poll-1');
    // Jumps a minute ahead: the second poll, three seconds after the first, comes at once.
    await page.clock.pauseAt(Date.now() + 60_000);
    await expect.poll(calls).toBe(2);
    await expect(firstDuration(page)).toHaveText('e2e-poll-2');

    // Nothing changed: twice as long each time, at most 30 seconds.
    for (const ms of [6_000, 12_000, 24_000, 30_000, 30_000]) {
      await expectPollAfter(page, calls, ms);
    }

    // Hidden: no poll, however long; shown again: a poll at once, and three seconds after it.
    await setPageVisibility(page, 'hidden');
    const hiddenAfter = calls();
    await page.clock.runFor(120_000);
    await page.waitForTimeout(300);
    expect(calls(), 'no poll while the page is hidden').toBe(hiddenAfter);
    await setPageVisibility(page, 'visible');
    await expect.poll(calls, { message: 'a poll as soon as the page is shown' }).toBe(hiddenAfter + 1);
    await expect(firstDuration(page)).toHaveText(`e2e-poll-${hiddenAfter + 1}`);
    await expectPollAfter(page, calls, 3_000);
    await expectPollAfter(page, calls, 6_000);

    // A change (another banner): three seconds again, then twice as long.
    changedFrom = calls() + 1;
    await expectPollAfter(page, calls, 12_000);
    await expect(bannerTitle(page)).toHaveText('e2e-changed');
    await expectPollAfter(page, calls, 3_000);
    await expectPollAfter(page, calls, 6_000);
    await expectNotReloaded(page);
  });
});

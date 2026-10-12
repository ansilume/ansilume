import { test, expect } from '@playwright/test';
import { expectFlash, newestJobId, postWithPageCsrf, templateId } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import { asUser, E2E_CANCELER } from '../../lib/session';
import {
  CURRENT_RUNNER,
  LEGACY_RUNNER,
  PASSWORD_FIELD,
  PLACEHOLDER,
  SECRET_TEMPLATE,
  SEEDED_JOB_ANSWER,
  TEXT_FIELD,
  VERBOSITY_NOTE,
  cancelOpenJob,
  expectNotInPage,
  freshAnswer,
  launchWithAnswers,
  logPoll,
  relaunchOpenJob,
  shownExtraVars,
  shownRunner,
} from '../../lib/survey-secrets';

/**
 * Jobs with a password survey answer, as e2e-admin. The answer is stored
 * only encrypted; the job page, its stored command and /job/log-poll show
 * $encrypted$ in its place.
 *
 * Fixtures (commands/E2eSurveySecretSeeder.php): two jobs of
 * e2e-secret-template launched with an answer and claimed on the server, as
 * CI has no runner: a running one claimed by a runner that takes the answers
 * as a file, and a finished one claimed by a 2.8 runner, which gets them
 * inline, so the claim pins and lowers the verbosity. No runner serves the
 * template's group, so the jobs launched here stay queued.
 * Operator and viewer: survey-secrets-rbac.spec.ts.
 */
test.describe('Jobs with password survey answers', () => {
  let template = '';

  test.beforeEach(async ({ page }) => {
    template = await templateId(page, SECRET_TEMPLATE);
  });

  test('a job claimed by a current runner shows $encrypted$ in its command, extra vars and log-poll', async ({ page }) => {
    const jobId = await newestJobId(page, template, 'running');
    await page.goto(`/job/view?id=${jobId}`);
    await expect(shownRunner(page)).toContainText(CURRENT_RUNNER);

    const command = page.locator('#exec-cmd-display');
    await expect(command).toContainText(`--extra-vars {"${TEXT_FIELD}":"staging","${PASSWORD_FIELD}":"${PLACEHOLDER}"}`);
    await expect(command).toContainText(' -vvvv ');
    await expect(command).not.toContainText('ANSIBLE_VERBOSITY');
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'staging', [PASSWORD_FIELD]: PLACEHOLDER });
    await expectNotInPage(page, SEEDED_JOB_ANSWER);

    const poll = await logPoll(page, jobId);
    expect(poll.body.execution_command).toBe(await command.textContent());
    expect(poll.raw).not.toContain(SEEDED_JOB_ANSWER);
  });

  test('a job claimed by a runner without encrypted answers ran pinned at -vvv, and its log says why', async ({ page }) => {
    const jobId = await newestJobId(page, template, 'succeeded');
    await page.goto(`/job/view?id=${jobId}`);
    await expect(shownRunner(page)).toContainText(LEGACY_RUNNER);

    const command = page.locator('#exec-cmd-display');
    await expect(command).toHaveText(/^\/usr\/bin\/env ANSIBLE_VERBOSITY=0 ansible-playbook /);
    await expect(command).toContainText(' -vvv ');
    await expect(command).not.toContainText('-vvvv');
    await expect(command).toContainText(`"${PASSWORD_FIELD}":"${PLACEHOLDER}"`);
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'staging', [PASSWORD_FIELD]: PLACEHOLDER });
    await expect(page.locator('#job-log')).toContainText(VERBOSITY_NOTE);
    await expectNotInPage(page, SEEDED_JOB_ANSWER);

    const poll = await logPoll(page, jobId);
    expect(poll.body.finished).toBe(true);
    expect(poll.body.chunks[poll.body.chunks.length - 1]).toEqual(expect.objectContaining({ stream: 'stderr', content: VERBOSITY_NOTE }));
    expect(poll.raw).not.toContain(SEEDED_JOB_ANSWER);
  });

  // Regression: the job page wrote log output into an inline script with
  // json_encode, so output with "</script>" or "<!--<script>" could run its
  // own script or swallow the rest of the page.
  test('job output with script text stays text, and the output after it still shows', async ({ page }) => {
    const jobId = await newestJobId(page, template, 'succeeded');
    await page.goto(`/job/view?id=${jobId}`);
    expect(
      await page.evaluate(() => (window as unknown as { __e2eLogXss?: number }).__e2eLogXss),
      'job output ran as a script',
    ).toBeUndefined();
    const log = page.locator('#job-log');
    await expect(log, 'the output, which "<!--<script>" used to swallow')
      .toContainText('<!--<script> e2e: </script><script>window.__e2eLogXss = 1</script>');
    await expect(log).toContainText(VERBOSITY_NOTE);
  });

  // Regression: a re-launch copied the job's extra vars, so it either brought
  // the answer back in plain text or, once answers were encrypted, sent the
  // placeholder; and a forged POST re-launched jobs that had not finished.
  test('a re-launch of a canceled job carries its password answer, still encrypted', async ({ page }) => {
    const answer = freshAnswer('relaunch');
    const jobId = await launchWithAnswers(page, template, { [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: answer });
    await expectFlash(page, 'success', `Job #${jobId} queued.`);
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER });
    await expectNotInPage(page, answer);

    // Not finished yet: no Re-launch button, and the action refuses as well.
    await expect(page.locator('form[action*="/relaunch"]')).toHaveCount(0);
    await page.goto('/job/index');
    await forgePost(page, `/job/relaunch?id=${jobId}`, {}, new RegExp(`/job/view\\?id=${jobId}$`));
    await expectFlash(page, 'danger', `Job #${jobId} cannot be re-launched in status "queued".`);

    await cancelOpenJob(page);
    const relaunchedId = await relaunchOpenJob(page, jobId);
    await expectFlash(page, 'success', `Re-launched as Job #${relaunchedId}.`);
    // Carried over: without the answer the launch would refuse the placeholder.
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER });
    await expectNotInPage(page, answer);
    expect((await logPoll(page, relaunchedId)).raw).not.toContain(answer);
  });

  test('a re-launch of a finished claimed job carries its answer, still encrypted', async ({ page }) => {
    const jobId = await newestJobId(page, template, 'succeeded');
    await page.goto(`/job/view?id=${jobId}`);
    const relaunchedId = await relaunchOpenJob(page, jobId);
    await expectFlash(page, 'success', `Re-launched as Job #${relaunchedId}.`);
    await expect(page.locator('#status-badge')).toContainText(/queued/i);
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'staging', [PASSWORD_FIELD]: PLACEHOLDER });
    await expectNotInPage(page, SEEDED_JOB_ANSWER);
  });

  // Regression: re-launching needed job.cancel, while the button asked for
  // job.launch. The default roles hold both or neither; this role holds
  // only job.view and job.cancel (commands/E2eCancelOnlyUserSeeder.php).
  test('a role that may cancel but not launch jobs sees no Re-launch button and may not re-launch', async ({ page, browser }, testInfo) => {
    const finishedId = await newestJobId(page, template, 'succeeded');
    const runningId = await newestJobId(page, template, 'running');

    await asUser(browser, testInfo, E2E_CANCELER, async (canceler) => {
      await canceler.goto(`/job/view?id=${runningId}`);
      await expect(canceler.locator('form[action*="/cancel"] button[type="submit"]')).toBeVisible();

      await canceler.goto(`/job/view?id=${finishedId}`);
      await expect(canceler.locator('#status-badge')).toContainText(/succeeded/i);
      await expect(canceler.locator('form[action*="/relaunch"]')).toHaveCount(0);
      await expectNotInPage(canceler, SEEDED_JOB_ANSWER);

      const response = await postWithPageCsrf(canceler, `/job/relaunch?id=${finishedId}`);
      expect(response.status(), 'a re-launch without job.launch').toBe(403);
    });
    // Nothing was launched: the newest job of the template is not the canceler's.
    await page.goto(`/job/index?template_id=${template}`);
    await expect(page.locator('#page-content table.table tbody tr').first()).not.toContainText('e2e-canceler');
  });
});

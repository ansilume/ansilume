import { test, expect } from '@playwright/test';
import { expectFlash, expectForbidden, newestJobId, templateId } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import {
  PASSWORD_FIELD,
  PLACEHOLDER,
  SECRET_TEMPLATE,
  SEEDED_JOB_ANSWER,
  TEXT_FIELD,
  cancelOpenJob,
  expectNotInPage,
  freshAnswer,
  launchWithAnswers,
  logPoll,
  relaunchOpenJob,
  shownExtraVars,
} from '../../lib/survey-secrets';

/**
 * Jobs with a password survey answer, as e2e-operator and e2e-viewer: every
 * role that may view a job sees $encrypted$ in place of the answer, on the
 * job page and in /job/log-poll. The viewer may neither cancel nor re-launch.
 *
 * Fixtures: commands/E2eSurveySecretSeeder.php (e2e-secret-template is in a
 * project without team, so both roles see its jobs). Admin side:
 * survey-secrets.spec.ts.
 */
test.describe('Jobs with password survey answers RBAC', () => {
  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });

  for (const role of ['operator', 'viewer']) {
    test(`${role} sees only $encrypted$ for the answers of claimed jobs, on their pages and in log-poll`, async ({ page }) => {
      const template = await templateId(page, SECRET_TEMPLATE);
      for (const status of ['running', 'succeeded']) {
        const jobId = await newestJobId(page, template, status);
        await page.goto(`/job/view?id=${jobId}`);
        await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
        expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'staging', [PASSWORD_FIELD]: PLACEHOLDER });
        await expect(page.locator('#exec-cmd-display')).toContainText(`"${PASSWORD_FIELD}":"${PLACEHOLDER}"`);
        await expectNotInPage(page, SEEDED_JOB_ANSWER);

        const poll = await logPoll(page, jobId);
        expect(poll.body.execution_command).toContain(`"${PASSWORD_FIELD}":"${PLACEHOLDER}"`);
        expect(poll.raw).not.toContain(SEEDED_JOB_ANSWER);
      }
    });
  }

  test('operator launches with a password answer, cancels and re-launches, and sees only $encrypted$', async ({ page }) => {
    const template = await templateId(page, SECRET_TEMPLATE);
    const answer = freshAnswer('operator');
    const jobId = await launchWithAnswers(page, template, { [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: answer });
    await expectFlash(page, 'success', `Job #${jobId} queued.`);
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER });
    await expectNotInPage(page, answer);

    await cancelOpenJob(page);
    const relaunchedId = await relaunchOpenJob(page, jobId);
    await expectFlash(page, 'success', `Re-launched as Job #${relaunchedId}.`);
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER });
    await expectNotInPage(page, answer);
    expect((await logPoll(page, relaunchedId)).raw).not.toContain(answer);
  });

  test('viewer sees no Cancel or Re-launch button and may not re-launch', async ({ page }) => {
    const template = await templateId(page, SECRET_TEMPLATE);
    const jobId = await newestJobId(page, template, 'succeeded');
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toContainText(/succeeded/i);
    await expect(page.locator('form[action*="/relaunch"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/cancel"]')).toHaveCount(0);

    await forgePost(page, `/job/relaunch?id=${jobId}`, {}, /\/job\/relaunch/);
    await expectForbidden(page);
  });
});

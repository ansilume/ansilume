import { test, expect, Page } from '@playwright/test';
import { templateId, userId } from '../../lib/helpers';
import {
  LAUNCHER_REFUSED,
  SCHEDULE_RUNS_AS_COLUMN,
  asOperator,
  auditContextOf,
  auditEntriesOf,
  decide,
  decisionForms,
  flash,
  requestOfJob,
  requestStatus,
  scheduleRow,
  toggleOpenSchedule,
} from '../../lib/approvals';
import {
  RUNS_AS_VIEW_NOTE,
  RUN_AS_SCHEDULE,
  RUN_AS_TEMPLATE,
  fireSchedule,
  jobIdsOfTemplate,
  otherYearlyCron,
  runsAs,
  templateFieldHint,
  templateFieldHintOf,
} from '../../lib/schedules';

/**
 * A schedule runs as the user who last saved a change to what or when it
 * runs, or enabled it (services/ScheduleRunAs.php); the web UI calls that
 * user "Runs as". Otherwise e2e-operator could change e2e-admin's schedule of
 * a gated job template, its job would start as e2e-admin, and e2e-operator
 * would approve what e2e-operator alone chose.
 *
 * Fixture (commands/E2eScheduleRunAsSeeder.php): e2e-run-as-schedule,
 * e2e-admin's disabled yearly schedule of e2e-run-as-template, which
 * e2e-approval-rule gates: e2e-admin and e2e-operator approve, one approval
 * is needed, and the launcher never decides. e2e-operator's team operates
 * the template's project. Each test first has e2e-admin change when the
 * schedule runs, so it starts from e2e-admin whatever an earlier run left.
 */

/** The user IDs of e2e-admin and e2e-operator, as numbers, for the run_as audit context. */
async function adminAndOperatorIds(page: Page): Promise<{ admin: number; operator: number }> {
  return { admin: Number(await userId(page, 'e2e-admin')), operator: Number(await userId(page, 'e2e-operator')) };
}

/** A change of a schedule as the audit log records it. */
interface AuditedChange {
  user: string;
  context: Record<string, unknown>;
}

/**
 * The newest `count` audit entries `action` about schedule #`id`, newest
 * first, each with its user and its context (run_as {from, to} when the
 * change handed the schedule over).
 */
async function scheduleAudit(page: Page, action: string, id: string, count: number): Promise<AuditedChange[]> {
  const entries = (await auditEntriesOf(page, action, 'schedule', id)).slice(0, count);
  expect(entries, `the newest ${count} ${action} entries of schedule #${id}`).toHaveLength(count);
  const audited: AuditedChange[] = [];
  for (const entry of entries) {
    audited.push({ user: entry.user, context: await auditContextOf(page, entry) });
  }
  return audited;
}

/** Saves the open schedule form; the schedule page opens with the success flash. */
async function saveScheduleForm(page: Page): Promise<void> {
  await Promise.all([
    page.waitForURL(/\/schedule\/view\?id=\d+/),
    page.locator('#schedule-form button[type="submit"]').click(),
  ]);
  await expect(flash(page, 'success')).toHaveText(`Schedule "${RUN_AS_SCHEDULE}" updated.`);
}

/**
 * Changes when schedule #`id` runs (to the other yearly time) through its
 * edit form as the user of `page`; the schedule page opens.
 */
async function changeWhenItRuns(page: Page, id: string): Promise<void> {
  await page.goto(`/schedule/update?id=${id}`);
  const cron = page.locator('#cron-input');
  await cron.fill(otherYearlyCron(await cron.inputValue()));
  await saveScheduleForm(page);
}

test.describe('Schedules run as whoever last armed them', () => {
  // Regression: editing another user's schedule let the editor approve its job.
  test('an operator who changes an admin\'s schedule runs it from then on, and may not approve the job it launches', async ({ page, browser, request, baseURL }, testInfo) => {
    test.slow();
    const users = await adminAndOperatorIds(page);
    const id = (await scheduleRow(page, RUN_AS_SCHEDULE)).id;
    await changeWhenItRuns(page, id);
    await expect(runsAs(page)).toHaveText('e2e-admin');
    await expect(page.getByTestId('schedule-runs-as')).toHaveText(RUNS_AS_VIEW_NOTE);

    // e2e-operator's form says so; changing when it runs hands it over.
    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/schedule/update?id=${id}`);
      await expect(templateFieldHintOf(operator)).toHaveText(templateFieldHint('e2e-admin'));
      await changeWhenItRuns(operator, id);
      await expect(runsAs(operator)).toHaveText('e2e-operator');
      await operator.goto(`/schedule/update?id=${id}`);
      await expect(templateFieldHintOf(operator)).toHaveText(templateFieldHint(null));
    });
    expect((await scheduleRow(page, RUN_AS_SCHEDULE)).cells[SCHEDULE_RUNS_AS_COLUMN]).toBe('e2e-operator');
    const [handedOver] = await scheduleAudit(page, 'schedule.updated', id, 1);
    expect(handedOver.user).toBe('e2e-operator');
    expect(handedOver.context.run_as).toEqual({ from: users.admin, to: users.operator });

    // A save that changes nothing keeps whom it runs as.
    await page.goto(`/schedule/update?id=${id}`);
    await expect(templateFieldHintOf(page)).toHaveText(templateFieldHint('e2e-operator'));
    await saveScheduleForm(page);
    await expect(runsAs(page)).toHaveText('e2e-operator');
    const [kept] = await scheduleAudit(page, 'schedule.updated', id, 1);
    expect(kept.user).toBe('e2e-admin');
    expect(kept.context).not.toHaveProperty('run_as');

    // Fired, it launches its job as e2e-operator; the job waits for approval.
    const template = await templateId(page, RUN_AS_TEMPLATE);
    const earlier = await jobIdsOfTemplate(page, template);
    const jobId = await fireSchedule(request, baseURL, RUN_AS_SCHEDULE);
    expect(earlier, 'the firing launched a new job').not.toContain(jobId);
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Awaiting Approval');
    await expect(page.locator('#page-content')).toContainText(`${RUN_AS_TEMPLATE} — launched by e2e-operator`);
    const requestId = await requestOfJob(page, jobId);

    // e2e-operator launched it, so e2e-operator may not decide on it.
    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await expect(requestStatus(operator)).toHaveText('Pending');
      await expect(operator.locator('#approval-launched-by')).toHaveText('e2e-operator');
      await expect(operator.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED);
      await expect(decisionForms(operator)).toHaveCount(0);
    });

    // e2e-admin, the rule's other approver, decides.
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-launched-by')).toHaveText('e2e-operator');
    await expect(page.locator('#approval-decision-note')).toHaveCount(0);
    await decide(page, 'reject', 'e2e: e2e-operator chose this run');
    await expect(flash(page, 'success')).toHaveText('Rejection recorded.');
    await expect(requestStatus(page)).toHaveText('Rejected');
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Rejected');

    // The firing enabled the schedule; disabling it keeps whom it runs as.
    await page.goto(`/schedule/view?id=${id}`);
    await toggleOpenSchedule(page);
    await expect(flash(page, 'success')).toHaveText(`Schedule "${RUN_AS_SCHEDULE}" disabled.`);
    await page.goto(`/schedule/view?id=${id}`);
    await expect(runsAs(page)).toHaveText('e2e-operator');
  });

  test('an operator who enables an admin\'s disabled schedule runs it from then on; disabling keeps that', async ({ page, browser }, testInfo) => {
    const users = await adminAndOperatorIds(page);
    const id = (await scheduleRow(page, RUN_AS_SCHEDULE)).id;
    await changeWhenItRuns(page, id);
    await expect(runsAs(page)).toHaveText('e2e-admin');
    await expect(page.locator('#page-content dl')).toContainText('Disabled');

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/schedule/view?id=${id}`);
      await toggleOpenSchedule(operator);
      await expect(flash(operator, 'success')).toHaveText(`Schedule "${RUN_AS_SCHEDULE}" enabled.`);
      await operator.goto(`/schedule/view?id=${id}`);
      await expect(runsAs(operator)).toHaveText('e2e-operator');

      await toggleOpenSchedule(operator);
      await expect(flash(operator, 'success')).toHaveText(`Schedule "${RUN_AS_SCHEDULE}" disabled.`);
      await operator.goto(`/schedule/view?id=${id}`);
      await expect(runsAs(operator)).toHaveText('e2e-operator');
      await expect(operator.locator('#page-content dl')).toContainText('Disabled');
    });

    // Both toggles were e2e-operator's; only the enabling handed the schedule over.
    const [disabling, enabling] = await scheduleAudit(page, 'schedule.toggled', id, 2);
    expect(disabling.user).toBe('e2e-operator');
    expect(disabling.context).not.toHaveProperty('run_as');
    expect(enabling.user).toBe('e2e-operator');
    expect(enabling.context.run_as).toEqual({ from: users.admin, to: users.operator });
  });
});

import { APIRequestContext, Locator, Page, expect } from '@playwright/test';

/**
 * Helpers for the schedule specs. Fixtures: commands/E2eScheduleRunAsSeeder.php.
 *
 * A schedule runs as the user who last armed it: who last saved a change to
 * its job template, cron expression, timezone or extra vars, or enabled it
 * (services/ScheduleRunAs.php). Renaming, disabling and a save that changes
 * nothing keep that user.
 */

/** The user the open schedule page says the schedule runs as (views/schedule/view.php). */
export function runsAs(page: Page): Locator {
  return page.locator('#schedule-runs-as');
}

/** services/ScheduleRunAs.php VIEW_NOTE: the schedule page's note under Runs as. */
export const RUNS_AS_VIEW_NOTE = 'Scheduled jobs run as this user, and only while they may launch the job template. '
  + 'Whoever saves a change to the job template, cron expression, timezone or extra vars, or enables '
  + 'the schedule, becomes the user it runs as.';

/** services/ScheduleRunAs.php FORM_NOTE: the edit form's note for a schedule that runs as another user. */
export const RUNS_AS_FORM_NOTE = 'Saving a change to the job template, cron expression, timezone or extra vars, '
  + 'or enabling the schedule, makes it run as you.';

/** services/ScheduleRunAs.php FORM_RUNS_AS_YOU: the form's hint for a new schedule, or one that runs as the user of the form. */
export const RUNS_AS_YOU = 'Scheduled jobs run as you.';

/**
 * services/ScheduleRunAs.php FORM_RUNS_AS_OTHER, then FORM_NOTE: the form's
 * hint for a schedule that runs as `runsAs`, another user than the one of the form.
 */
export function runsAsOther(runsAs: string): string {
  return `Scheduled jobs run as ${runsAs}. ${RUNS_AS_FORM_NOTE}`;
}

/**
 * The hint of the job template field of the schedule form
 * (views/schedule/form.php): `runsAs` is the user the schedule runs as when
 * that is not the user of the form, null when it is (or for a new schedule).
 */
export function templateFieldHint(runsAs: string | null): string {
  return 'Lists the job templates you may launch. ' + (runsAs === null ? RUNS_AS_YOU : runsAsOther(runsAs));
}

/** The hint of the job template field of the open schedule form. */
export function templateFieldHintOf(page: Page): Locator {
  return page.locator('.field-schedule-job_template_id .hint-block');
}

/**
 * e2e-admin's disabled schedule of e2e-run-as-template, which
 * e2e-approval-rule gates (e2e-admin and e2e-operator approve, one approval).
 * e2e-operator's team operates the template's project, so e2e-operator may
 * change the schedule. Every seed gives it back to e2e-admin.
 */
export const RUN_AS_SCHEDULE = 'e2e-run-as-schedule';
export const RUN_AS_TEMPLATE = 'e2e-run-as-template';

/**
 * Two yearly cron expressions (1 January, 00:00 and 00:30 UTC): the schedule
 * never comes due by itself while the specs run. Switching between them
 * changes when it runs.
 */
export const YEARLY_CRONS = ['0 0 1 1 *', '30 0 1 1 *'] as const;

/** The other one of YEARLY_CRONS than `cron` (the first one for any other value). */
export function otherYearlyCron(cron: string): string {
  return cron === YEARLY_CRONS[0] ? YEARLY_CRONS[1] : YEARLY_CRONS[0];
}

/**
 * Makes the schedule `name` due and runs the due schedules once
 * (controllers/E2eController.php actionFireSchedule, YII_DEBUG only), which
 * enables it; the ID of the newest job of its job template.
 */
export async function fireSchedule(request: APIRequestContext, baseURL: string | undefined, name: string): Promise<string> {
  const fired = await request.post(`${baseURL}/e2e/fire-schedule`, { params: { name } });
  expect(fired.status(), `fire-schedule answered ${fired.status()}`).toBe(200);
  const { latest_job_id: jobId } = (await fired.json()) as { latest_job_id: number };
  expect(jobId, 'a job of the schedule\'s template').toBeGreaterThan(0);
  return String(jobId);
}

/** The IDs of the jobs the job list shows for job template #`templateId` (the first page, newest first). */
export async function jobIdsOfTemplate(page: Page, templateId: string): Promise<string[]> {
  await page.goto(`/job/index?template_id=${templateId}`);
  const hrefs = await page.locator('#page-content table.table tbody tr a[href*="/job/view"]').evaluateAll(
    (links) => links.map((link) => link.getAttribute('href') ?? ''),
  );
  return [...new Set(hrefs.map((href) => href.match(/[?&]id=(\d+)/)?.[1] ?? '').filter((id) => id !== ''))];
}

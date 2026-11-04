import { Locator, Page, expect } from '@playwright/test';
import { templateId } from './helpers';

/**
 * Failed jobs that a later run of the same job template recovered
 * (services/RecoveredFailures.php): the dashboard leaves them out of Failed
 * Jobs (last 24h) and counts them in a muted line, the job page names the
 * run that recovered them, and the job list shows "recovered by #N" next to
 * their status and filters by "Recovered". The texts are those of
 * RecoveredFailures.
 *
 * Fixtures: commands/E2eRecoveredFailureSeeder.php, restored by every seed
 * on a project that no team has, so every role sees them:
 * e2e-recovery-template has a job that failed two hours before the seed and
 * the success that recovered it; e2e-recovery-open-template a job that
 * failed three hours before the seed, which no later run recovered;
 * e2e-recovery-old-template a job that failed three days before the seed and
 * the success that recovered it, outside the dashboard's last 24 hours.
 */
export const RECOVERY_TEMPLATE = 'e2e-recovery-template';
export const OPEN_FAILURE_TEMPLATE = 'e2e-recovery-open-template';
export const OLD_RECOVERY_TEMPLATE = 'e2e-recovery-old-template';

/**
 * The dashboard's line on the recovered failures of the last 24 hours, with
 * its link. A RegExp is matched against the text as it is, with the line
 * break and indentation of the markup before the link.
 */
export const DASHBOARD_LINE = /^\s*(1 failed job of the last 24 hours was|\d+ failed jobs of the last 24 hours were) followed by a successful run of the same job template\.\s+Show them\s*$/;

/**
 * The note of the job list while it lists only the jobs that finished since
 * a time (finished_since), with its link that removes that filter
 * (JobSearchForm::FINISHED_SINCE_NOTE, FINISHED_SINCE_CLEAR).
 */
export const FINISHED_SINCE_NOTE = /^\s*Only jobs that finished since \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\s+Remove this filter\s*$/;

/** The seeded jobs, by ID. */
export interface RecoveryJobs {
  /** The failure of e2e-recovery-template, which a later run recovered. */
  failure: string;
  /** The success of e2e-recovery-template that recovered it. */
  success: string;
  /** The failure of e2e-recovery-open-template, which no later run recovered. */
  open: string;
  /** The failure of e2e-recovery-old-template, three days old, which a later run recovered. */
  old: string;
}

/** The note of the job page of a failure that job #`success` recovered. */
export function jobPageNote(success: string): string {
  return `A later run of this job template succeeded: #${success}`;
}

/** What the job list shows next to the status of a failure that job #`success` recovered. */
export function listNote(success: string): string {
  return `recovered by #${success}`;
}

/** The IDs of the seeded jobs, from the job list filtered by job template and status. */
export async function recoveryJobs(page: Page): Promise<RecoveryJobs> {
  const template = await templateId(page, RECOVERY_TEMPLATE);
  const openTemplate = await templateId(page, OPEN_FAILURE_TEMPLATE);
  const oldTemplate = await templateId(page, OLD_RECOVERY_TEMPLATE);
  return {
    failure: await onlyJobOf(page, `/job/index?template_id=${template}&status=failed`),
    success: await onlyJobOf(page, `/job/index?template_id=${template}&status=succeeded`),
    open: await onlyJobOf(page, `/job/index?template_id=${openTemplate}&status=failed`),
    old: await onlyJobOf(page, `/job/index?template_id=${oldTemplate}&status=failed`),
  };
}

/** The IDs of the jobs the open job list shows, in its order. */
export async function listedJobIds(page: Page): Promise<string[]> {
  return page.locator('#page-content table.table tbody tr td:first-child a').evaluateAll(
    (links) => links.map((link) => (link.getAttribute('href') ?? '').match(/[?&]id=(\d+)/)?.[1] ?? ''),
  );
}

/** The row of job #`jobId` in the open job list. */
export function listRow(page: Page, jobId: string): Locator {
  return page.locator('#page-content table.table tbody tr').filter({ has: page.locator(`td:first-child a[href$="/job/view?id=${jobId}"]`) });
}

/** How many failures Failed Jobs (last 24h) lists at most: the newest (controllers/SiteController.php). */
const PANEL_ROWS = 5;

/**
 * The dashboard as the signed-in user sees it: Failed Jobs (last 24h) never
 * lists the recovered failure, and the muted line counts the recovered
 * failures of the last 24 hours and links their list, which names the run
 * that recovered the seeded one, and lists only those that finished since
 * the window began: not the old one (regression: the link listed every
 * recovered failure, of any age).
 *
 * The panel lists the newest open failures of the last 24 hours. While it
 * lists fewer than five, it lists every one: the seeded open failure too,
 * which is older than the recovered one, so the recovered one is missing
 * because a later run recovered it. Jobs that specs launched before may
 * fail on the stack's runners and fill the panel with newer failures; then
 * only the absence is checked.
 */
export async function expectTheDashboardLeavesOutTheRecoveredFailure(page: Page, jobs: RecoveryJobs): Promise<void> {
  await page.goto('/site/index');
  const panel = page.locator('#dashboard-failed-jobs');
  await expect(panel.locator(`a[href$="/job/view?id=${jobs.failure}"]`), 'the recovered failure is not listed').toHaveCount(0);
  if (await panel.locator('tbody tr').count() < PANEL_ROWS) {
    await expect(panel.locator(`a[href$="/job/view?id=${jobs.open}"]`), 'the open failure is listed').toHaveCount(1);
  }

  const line = page.locator('#dashboard-recovered-failures');
  await expect(line).toHaveText(DASHBOARD_LINE);
  await expect(line).toHaveClass(/\btext-body-secondary\b/);
  await line.getByRole('link', { name: 'Show them' }).click();
  await expect(page).toHaveURL(/\/job\/index\?recovered=1&finished_since=\d+$/);
  await expect(page.locator('#job-filter-recovered')).toHaveValue('1');
  await expect(page.locator('#job-filter-finished-since')).toHaveText(FINISHED_SINCE_NOTE);
  const ids = await listedJobIds(page);
  expect(ids, 'the recovered failure').toContain(jobs.failure);
  expect(ids, 'not the open one').not.toContain(jobs.open);
  expect(ids, 'not its recovering run').not.toContain(jobs.success);
  expect(ids, 'not the recovered failure of three days ago').not.toContain(jobs.old);
  await expect(listRow(page, jobs.failure).locator('[data-recovered-by]')).toHaveText(listNote(jobs.success));
}

/**
 * The job list that the dashboard's line opened keeps its window: Filter
 * keeps finished_since, and the note's link removes it, which lists the
 * recovered failure of three days ago too, with recovered=1 kept.
 */
export async function expectTheListKeepsTheWindowOfTheDashboard(page: Page, jobs: RecoveryJobs): Promise<void> {
  await page.goto('/site/index');
  await page.locator('#dashboard-recovered-failures').getByRole('link', { name: 'Show them' }).click();
  await expect(page).toHaveURL(/[?&]finished_since=\d+$/);
  const since = new URL(page.url()).searchParams.get('finished_since');

  await page.locator('#page-content form').getByRole('button', { name: 'Filter' }).click();
  await expect(page).toHaveURL(new RegExp(`[?&]recovered=1&finished_since=${since}$`));
  await expect(page.locator('#job-filter-finished-since')).toHaveText(FINISHED_SINCE_NOTE);
  const kept = await listedJobIds(page);
  expect(kept, 'the recovered failure').toContain(jobs.failure);
  expect(kept, 'not the old one').not.toContain(jobs.old);
  await expect(page.locator('#page-content').getByRole('link', { name: 'Clear filters' })).toHaveCount(1);

  await page.locator('#job-filter-finished-since').getByRole('link', { name: 'Remove this filter' }).click();
  await expect(page).toHaveURL(/[?&]recovered=1$/);
  expect(page.url(), 'the filter is gone').not.toContain('finished_since');
  await expect(page.locator('#job-filter-recovered')).toHaveValue('1');
  await expect(page.locator('#job-filter-finished-since')).toHaveCount(0);
  const every = await listedJobIds(page);
  expect(every, 'the recovered failure').toContain(jobs.failure);
  expect(every, 'and the one of three days ago').toContain(jobs.old);
  expect(every, 'not the open one').not.toContain(jobs.open);
}

/**
 * The job page of the recovered failure names the run that recovered it and
 * links it; neither that run's page nor the open failure's has a note.
 */
export async function expectTheJobPageNamesTheRecoveringRun(page: Page, jobs: RecoveryJobs): Promise<void> {
  await page.goto(`/job/view?id=${jobs.failure}`);
  await expect(page.locator('#status-badge')).toHaveText('Failed');
  const note = page.locator('#job-recovered-note');
  await expect(note).toHaveText(jobPageNote(jobs.success));
  await note.getByRole('link', { name: `#${jobs.success}` }).click();
  await expect(page).toHaveURL(new RegExp(`/job/view\\?id=${jobs.success}$`));
  await expect(page.locator('#status-badge')).toHaveText('Succeeded');
  await expect(page.locator('#job-recovered-note')).toHaveCount(0);

  await page.goto(`/job/view?id=${jobs.open}`);
  await expect(page.locator('#status-badge')).toHaveText('Failed');
  await expect(page.locator('#job-recovered-note')).toHaveCount(0);
}

/**
 * The job list of e2e-recovery-template shows "recovered by #N" next to the
 * status of the recovered failure, and nothing next to its recovering run.
 */
export async function expectTheListNamesTheRecoveringRun(page: Page, jobs: RecoveryJobs): Promise<void> {
  await page.goto(`/job/index?template_id=${await templateId(page, RECOVERY_TEMPLATE)}`);
  const note = listRow(page, jobs.failure).locator('td:nth-child(2) [data-recovered-by]');
  await expect(note).toHaveText(listNote(jobs.success));
  await expect(note).toHaveAttribute('data-recovered-by', jobs.success);
  await expect(note.getByRole('link', { name: `#${jobs.success}` })).toHaveAttribute('href', new RegExp(`/job/view\\?id=${jobs.success}$`));
  await expect(listRow(page, jobs.success).locator('[data-recovered-by]')).toHaveCount(0);
}

/** Finds the only job a filtered job list shows. */
async function onlyJobOf(page: Page, url: string): Promise<string> {
  await page.goto(url);
  const ids = await listedJobIds(page);
  expect(ids, `exactly one job on ${url}: run php yii e2e/seed`).toHaveLength(1);
  return ids[0];
}

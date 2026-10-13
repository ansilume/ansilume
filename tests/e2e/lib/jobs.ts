import { APIRequestContext, Locator, Page, expect } from '@playwright/test';
import { templateId } from './helpers';
import { indexRows } from './scoping';

/**
 * Finds seeded jobs. Fixtures: commands/E2eArtifactSeeder.php and
 * commands/E2eLogStreamSeeder.php, which mark their jobs of e2e-template with
 * an execution command of their own.
 */

/** The job template of the seeded jobs. */
export const SEEDED_JOB_TEMPLATE = 'e2e-template';

/** The seeded job with artifacts. */
export const ARTIFACT_JOB = 'e2e-artifact-job';

/** The seeded finished and running jobs with log chunks. */
export const LOGSTREAM_FINISHED_JOB = 'e2e-logstream-finished';
export const LOGSTREAM_RUNNING_JOB = 'e2e-logstream-running';

/**
 * The ID of the job of e2e-template whose execution command is `command`.
 * Every job of the template is a candidate: the jobs launched since the seed
 * push a seeded job off the first page of the job list, newest first. The
 * command comes from /job/log-poll, as the job page polls it.
 */
export async function seededJobId(page: Page, command: string): Promise<string> {
  const template = await templateId(page, SEEDED_JOB_TEMPLATE);
  for (const job of await indexRows(page, `/job/index?template_id=${template}`)) {
    // A sequence past every chunk: only the job's fields, no log.
    const response = await page.request.get(`/job/log-poll?id=${job.id}&after=2147483647`);
    expect(response.status(), `log-poll of job #${job.id}`).toBe(200);
    const body = (await response.json()) as { execution_command: string | null };
    if (body.execution_command === command) {
      return job.id;
    }
  }
  throw new Error(`No job of ${SEEDED_JOB_TEMPLATE} has the execution command ${command}: run php yii e2e/seed`);
}

/**
 * A job of the e2e- job template `template` that waits for a runner
 * (queued), from the YII_DEBUG hook /e2e/create-cancelable-job
 * (controllers/E2eController.php): its id. The hook writes the job row
 * directly, without a launch, and its lookup by name (where() replaces the
 * deleted_at scope of JobTemplate::find()) finds a soft-deleted template too:
 * such a job is one that waited when its template was deleted. No runner
 * claims a job of a soft-deleted template (JobClaimService).
 */
export async function queuedJobOf(request: APIRequestContext, baseURL: string | undefined, template: string): Promise<string> {
  const made = await request.post(`${baseURL}/e2e/create-cancelable-job`, { params: { template } });
  expect(made.status(), `create-cancelable-job answered ${made.status()}: ${await made.text()}`).toBe(200);
  const { job_id: jobId } = (await made.json()) as { job_id: number };
  expect(jobId, 'the job').toBeGreaterThan(0);
  return String(jobId);
}

/**
 * The ID of the newest job of the job template #`template`, from the job list
 * (newest first), '' when it has none: a launch that was refused leaves it
 * as it was.
 */
export async function newestJobOf(page: Page, template: string): Promise<string> {
  await page.goto(`/job/index?template_id=${template}`);
  await expect(page.locator('#page-content select[name="template_id"]'), 'the job list, filtered by the template').toHaveValue(template);
  const hrefs = await page.locator('#page-content table.table tbody tr td:first-child a')
    .evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
  return hrefs[0]?.match(/[?&]id=(\d+)/)?.[1] ?? '';
}

/** The Template entry of the details of the open job page: the name, '(deleted)' after it, or '—' without a template. */
export function jobTemplateDetail(page: Page): Locator {
  return page.locator('#page-content dl dt', { hasText: /^Template$/ }).locator('xpath=following-sibling::dd[1]');
}

import { Page, expect } from '@playwright/test';

/**
 * Helpers for the specs of password survey answers. A value is a password
 * answer when its key is a password field of the job template's survey; it
 * is stored only encrypted, and every page, log-poll response and stored
 * command shows PLACEHOLDER instead.
 *
 * Fixtures: commands/E2eSurveySecretSeeder.php.
 */

/** What every page shows instead of a password answer. */
export const PLACEHOLDER = '$encrypted$';

export const SECRET_TEMPLATE = 'e2e-secret-template';
export const SECRET_WORKFLOW = 'e2e-secret-workflow';
export const SECRET_SCHEDULE = 'e2e-secret-schedule';
export const CURRENT_RUNNER = 'e2e-secret-runner-current';
export const LEGACY_RUNNER = 'e2e-secret-runner-legacy';
export const TEXT_FIELD = 'app_env';
export const PASSWORD_FIELD = 'db_password';

/** E2E test values the seeder stored as answers: E2eSurveySecretSeeder::JOB_ANSWER and ::SCHEDULE_ANSWER. */
export const SEEDED_JOB_ANSWER = 'e2e-seeded-job-answer-Qx71';
export const SEEDED_SCHEDULE_ANSWER = 'e2e-seeded-schedule-answer-Rw52';

/** The log note of a job whose claim lowered the verbosity (services/JobClaimSecrets.php). */
export const VERBOSITY_NOTE = '[ansilume] Verbosity was lowered from 4 to 3: this runner does not support encrypted '
  + 'survey answers and would print them at higher verbosity. Update the runner image.';

/** One response of GET /job/log-poll. */
export interface LogPoll {
  status: string;
  chunks: { sequence: number; stream: string; content: string }[];
  finished: boolean;
  execution_command: string | null;
}

/** A new answer for a launch: unique, so finding it anywhere is a leak. */
export function freshAnswer(label: string): string {
  return `e2e-${label}-answer-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}

/** The job's launch-time extra vars, as its page shows them. */
export async function shownExtraVars(page: Page): Promise<Record<string, unknown>> {
  const card = page.locator('.card', { has: page.locator('.card-header', { hasText: 'Extra Vars (launch-time)' }) });
  return JSON.parse((await card.locator('pre').textContent()) ?? '') as Record<string, unknown>;
}

/** The runner the job page names. */
export function shownRunner(page: Page) {
  return page.locator('dt', { hasText: /^Runner$/ }).locator('xpath=following-sibling::dd[1]');
}

/** GET /job/log-poll from the start, with the page's session: the raw body and the parsed one. */
export async function logPoll(page: Page, jobId: string): Promise<{ raw: string; body: LogPoll }> {
  const response = await page.request.get(`/job/log-poll?id=${jobId}&after=-1`);
  expect(response.status(), 'log-poll answers').toBe(200);
  const raw = await response.text();
  return { raw, body: JSON.parse(raw) as LogPoll };
}

/** The answer is nowhere in the page: not in its text, attributes or scripts. */
export async function expectNotInPage(page: Page, answer: string): Promise<void> {
  expect(await page.content(), 'the page never carries the password answer').not.toContain(answer);
}

/** Submits the template's launch form with these survey answers; the id of the new job. */
export async function launchWithAnswers(page: Page, template: string, answers: Record<string, string>): Promise<string> {
  await page.goto(`/job-template/launch?id=${template}`);
  for (const [name, value] of Object.entries(answers)) {
    await page.locator(`#survey-${name}`).fill(value);
  }
  await Promise.all([
    page.waitForURL(/\/job\/view\?id=\d+/),
    page.locator('#launch-form button[type="submit"]').click(),
  ]);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/** Cancels the job whose page is open. */
export async function cancelOpenJob(page: Page): Promise<void> {
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('form[action*="/cancel"] button[type="submit"]').click();
  await expect(page.locator('#status-badge')).toContainText(/canceled/i);
}

/** Re-launches the job whose page is open; the id of the new job, whose page opens. */
export async function relaunchOpenJob(page: Page, jobId: string): Promise<string> {
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL((url) => url.pathname === '/job/view' && url.searchParams.get('id') !== jobId),
    page.locator('form[action*="/relaunch"] button[type="submit"]').click(),
  ]);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

import { Locator, Page, expect } from '@playwright/test';
import { IndexRow, idWhere, indexRows, rowsWhere } from './scoping';

/**
 * Helpers for what workflow runs wait for: the run page's banner
 * (#wj-wait-banner) and steps table, the Workflow Jobs list, the Active runs
 * card of a workflow template page and the dashboard. The texts are those of
 * services/runstate/Texts.php and Display.php.
 *
 * Fixtures: commands/E2eWorkflowWaitSeeder.php, restored by every seed (the
 * waiting run of e2e-mixed-gated-wf, the run of e2e-long-paused-workflow
 * paused for 26 hours, the job e2e-unreachable-job), and the waiting run of
 * e2e-beta-approval-wf (commands/E2eWorkflowScopingSeeder.php).
 */

/**
 * The job step e2e-mixed-gated-wf-gated on e2e-alpha-gated-tmpl, which
 * e2e-alpha-gate-rule gates (e2e-admin and e2e-operator approve), then a job
 * step on e2e-beta-tmpl; e2e-admin launched its run, whose first job waits
 * for approval. e2e-operator's team operates e2e-alpha-proj only: they see the
 * request through the job template, never the workflow.
 */
export const MIXED_WORKFLOW = 'e2e-mixed-gated-wf';
export const MIXED_GATED_STEP = 'e2e-mixed-gated-wf-gated';
export const ALPHA_GATED_TEMPLATE = 'e2e-alpha-gated-tmpl';
export const ALPHA_GATE_RULE = 'e2e-alpha-gate-rule';

/** A pause step, then a job step; its run has been paused at the pause step for 26 hours. */
export const LONG_PAUSED_WORKFLOW = 'e2e-long-paused-workflow';
export const LONG_PAUSED_PAUSE = 'e2e-long-paused-pause';

/** The execution command that marks the seeded failed job of e2e-template whose one host was unreachable. */
export const UNREACHABLE_JOB = 'e2e-unreachable-job';
export const UNREACHABLE_TASK = 'e2e-unreachable-task';

/**
 * commands/E2eWorkflowScopingSeeder.php: an approval step on
 * e2e-beta-approval-rule, then a job step on e2e-beta-tmpl, with a run that
 * waits at the approval step. The rule's approvers are e2e-viewer (no
 * approval.decide) and e2e-operator (cannot see the request): nobody can decide.
 */
export const BETA_APPROVAL_WORKFLOW = 'e2e-beta-approval-wf';
export const BETA_APPROVAL_STEP = 'e2e-beta-approval-wf-approve';
export const BETA_APPROVAL_RULE = 'e2e-beta-approval-rule';

/** commands/E2eWorkflowPausedSeeder.php: a pause step, then a job step. */
export const PAUSED_WORKFLOW = 'e2e-paused-workflow';
export const PAUSED_STEP = 'Wait for manual resume';

/** The display states (Display::label()); a waiting run is stored as running. */
export const AWAITING_APPROVAL = 'Awaiting Approval';
export const PAUSED = 'Paused';
export const QUEUED = 'Queued';
export const RUNNING = 'Running';
export const SUCCEEDED = 'Succeeded';
export const FAILED = 'Failed';
export const CANCELED = 'Canceled';

/** Texts::PERSONAL_*: how the viewer stands to the request a run waits for. */
export const PERSONAL_MAY_DECIDE = 'You can decide on this request.';
export const PERSONAL_LAUNCHER = 'You launched this run, so another approver must decide.';
export const PERSONAL_NOT_ELIGIBLE = 'You are on the rule\'s approver list, but may not decide on this request.';

/** Texts::REVIEW_BUTTON: the banner's link to the request, for a viewer who may decide. */
export const REVIEW_BUTTON = 'Review & decide';

/** Texts::WARNING_UNDECIDABLE */
export const WARNING_UNDECIDABLE = 'Too few of the rule\'s approvers can still decide, so this request can no '
  + 'longer be approved. An admin can add approvers to the rule, or cancel the run.';

/** Texts::FACT_NEVER_EXPIRES */
export const NEVER_EXPIRES = 'never expires';

/** services/WorkflowCancellationService.php STEP_RUN_CANCELED: the error of a step a run cancel ended. */
export const STEP_RUN_CANCELED = 'Not finished: the run was canceled.';

/** controllers/WorkflowJobController.php CANCELED */
export const RUN_CANCELED_FLASH = 'Workflow canceled.';

/** Texts::LIVE_STOPPED_RUN: the run page's note once its poll was refused (401, 403, 404). */
export const LIVE_STOPPED_RUN = 'Live updates stopped. Reload the page to keep following the run.';

/** Texts::LIVE_STOPPED_JOB: the job page's note once its poll was refused (401, 403, 404). */
export const LIVE_STOPPED_JOB = 'Live updates stopped. Reload the page to keep following the job.';

/**
 * Sets what the open page reports as its visibility (document.hidden,
 * document.visibilityState) and tells it, as a browser does when its tab is
 * hidden or shown again (a visibilitychange event).
 */
export async function setPageVisibility(page: Page, state: 'hidden' | 'visible'): Promise<void> {
  await page.evaluate((visibility) => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => visibility === 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  }, state);
}

/** A time as TimeHelper::stamp() writes it: the time of day today, the date too on another day. */
export const STAMP = '(\\d{2}:\\d{2}:\\d{2}|\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2})';

/** TimeHelper::stamp() of a time on another day than today, as a pattern. */
export const OTHER_DAY = '\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}';

/** TimeHelper::stamp() of a time on another day than today. */
export const OTHER_DAY_STAMP = new RegExp(`^${OTHER_DAY}$`);

/** Texts::FACT_SINCE */
export const WAITING_SINCE = new RegExp(`^waiting since ${STAMP}$`);

/** Texts::BANNER_APPROVAL_STEP */
export function bannerApprovalStep(step: string): string {
  return `Waiting for approval at step "${step}".`;
}

/** Texts::BANNER_GATED_JOB */
export function bannerGatedJob(jobId: string, step: string): string {
  return `Job #${jobId} of step "${step}" waits for approval.`;
}

/** Texts::BANNER_QUEUED */
export function bannerQueued(jobId: string, step: string): string {
  return `Job #${jobId} of step "${step}" is queued: it waits for a runner.`;
}

/** Texts::BANNER_PAUSED */
export function bannerPaused(step: string): string {
  return `Paused at step "${step}" until someone resumes the run.`;
}

/** Texts::FACT_REQUEST: the banner's first fact, the request. */
export function factRequest(requestId: string): string {
  return `Approval Request #${requestId}`;
}

/** Texts::FACT_RULE */
export function factRule(rule: string): string {
  return `Rule "${rule}"`;
}

/** Texts::FACT_APPROVALS */
export function factApprovals(counted: number, required: number): string {
  return `${counted} of ${required} approval(s)`;
}

/** Texts::FACT_OPEN_APPROVERS */
export function factOpenApprovers(open: number): string {
  return `${open} approver(s) can still decide`;
}

/** Texts::LAUNCHED */
export function launched(workflow: string): string {
  return `Workflow "${workflow}" launched.`;
}

/** Texts::LAUNCHED_PAUSED */
export function launchedPaused(workflow: string, step: string): string {
  return `Workflow "${workflow}" launched. It is paused at step "${step}".`;
}

/**
 * Texts::LAUNCHED_AWAITING_APPROVAL, with the request's ID as group 1: the
 * request does not exist before the launch.
 */
export function launchedAwaitingApproval(workflow: string, step: string): RegExp {
  return new RegExp(`^\\s*${regexLiteral(`Workflow "${workflow}" launched. Step "${step}" waits for approval (Approval Request #`)}(\\d+)\\)\\.\\s*$`);
}

/** PollPayload::REQUEST_LABEL: a step's request in the steps table, the run list and the Active runs card. */
export function requestLabel(requestId: string): string {
  return `Request #${requestId}`;
}

/** Texts::FOR_WORKFLOW_STEP: what a request of a workflow step is for, to a user who may view the workflow. */
export function forWorkflowStep(workflow: string, step: string, runId: string): string {
  return `${workflow} › ${step} (Workflow Job #${runId})`;
}

/** Texts::FOR_JOB_TEMPLATE: what a request is for, by its job's template only. */
export function forJobTemplate(template: string): string {
  return `${template} (job template)`;
}

/** Texts::STEP_WAITING_MARKER: the Type cell of a template's step where runs wait. */
export function stepWaitingMarker(runs: number): string {
  return `${runs} run(s) waiting`;
}

/** Texts::ACTIVE_RUN_AT, with the time as a pattern. */
export function activeRunAt(step: string, since: string = STAMP): RegExp {
  return new RegExp(`^${regexLiteral(`at step "${step}" since `)}${since}$`);
}

/** Texts::ACTIVE_RUN_BY */
export function activeRunBy(user: string): string {
  return `launched by ${user}`;
}

/** Texts::JOB_APPROVAL_NOTE */
export function jobApprovalNote(requestId: string): string {
  return `This job waits for Approval Request #${requestId}.`;
}

/** Texts::JOB_WORKFLOW_ORIGIN: the job page's Workflow Job row. */
export function jobWorkflowOrigin(runId: string, step: string): string {
  return `#${runId}, step "${step}"`;
}

/** Texts::PLACEHOLDER_SUBTITLE */
export function placeholderSubtitle(step: string, runId: string): string {
  return `Approval step "${step}" of Workflow Job #${runId}`;
}

/** Texts::PLACEHOLDER_TEMPLATE */
export const PLACEHOLDER_TEMPLATE = 'none (placeholder of an approval step)';

/** Texts::PLACEHOLDER_LABEL: a placeholder's Template cell in the jobs list. */
export function placeholderLabel(step: string, runId: string): string {
  return `Approval step "${step}" (Workflow Job #${runId})`;
}

/** Texts::PLACEHOLDER_NOTE */
export function placeholderNote(step: string, runId: string): string {
  return `This job only holds the approval request of step "${step}" in Workflow Job #${runId} and never runs: `
    + 'approved, the run goes on; rejected, timed out or canceled, the step fails.';
}

/** The banner of the open run page, which says what the run waits for (hidden when it waits for nothing). */
export function waitBanner(page: Page): Locator {
  return page.locator('#wj-wait-banner');
}

export function bannerTitle(page: Page): Locator {
  return waitBanner(page).locator('[data-banner-title]');
}

export function bannerFacts(page: Page): Locator {
  return waitBanner(page).locator('[data-banner-facts] > li');
}

/** The request among the banner's facts: a link only for a viewer with approval.view. */
export function bannerRequest(page: Page): Locator {
  return waitBanner(page).locator('[data-banner-request]');
}

export function bannerPersonal(page: Page): Locator {
  return waitBanner(page).locator('[data-banner-personal]');
}

export function bannerWarning(page: Page): Locator {
  return waitBanner(page).locator('[data-banner-warning]');
}

/** The banner's Review & decide link, only for a viewer who may decide now. */
export function reviewLink(page: Page): Locator {
  return page.locator('#wj-review-link');
}

/** The run's state badge on the open run page. */
export function runState(page: Page): Locator {
  return page.locator('#wj-status');
}

/** The state badge of a step row of the open run page. */
export function stepBadge(row: Locator): Locator {
  return row.locator('[data-wjs-status-cell] .badge');
}

/**
 * The ID of the approval request a step row of the open run page names
 * ("Request #R"): a link, or a span for a viewer without approval.view, so
 * read from the text.
 */
export async function stepRequestId(row: Locator): Promise<string> {
  const request = row.locator('[data-wjs-request-link]');
  await expect(request).toHaveText(/^Request #\d+$/);
  return ((await request.textContent()) ?? '').trim().replace('Request #', '');
}

/** The ID of the approval request the banner of the open run page names. */
export async function bannerRequestId(page: Page): Promise<string> {
  await expect(bannerRequest(page)).toHaveText(/^Approval Request #\d+$/);
  return ((await bannerRequest(page).textContent()) ?? '').trim().replace('Approval Request #', '');
}

/**
 * Launches the workflow template `name` from its page as the user of `page`
 * and accepts the confirm dialog; the run's ID, whose page is open then.
 */
export async function launchWorkflow(page: Page, name: string): Promise<string> {
  await page.goto(`/workflow-template/view?id=${await workflowTemplateId(page, name)}`);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-job\/view\?id=\d+$/),
    page.locator('#page-content form[action*="/workflow-template/launch"] button[type="submit"]').click(),
  ]);
  const runId = new URL(page.url()).searchParams.get('id') ?? '';
  expect(runId, `the run of ${name}`).toMatch(/^\d+$/);
  return runId;
}

/** The ID of the workflow template `name`, from the workflow template list (columns: Name, ...). */
export async function workflowTemplateId(page: Page, name: string): Promise<string> {
  return idWhere(await indexRows(page, '/workflow-template/index'), 0, name);
}

/** Columns of the Workflow Jobs list: #, Workflow, Status, Current Step, Launched By, Started, Finished. */
export const RUN_WORKFLOW_COLUMN = 1;
export const RUN_STATUS_COLUMN = 2;
export const RUN_CURRENT_STEP_COLUMN = 3;
export const RUN_LAUNCHER_COLUMN = 4;

/** The headings of the Workflow Jobs list. */
export const RUN_LIST_COLUMNS = ['#', 'Workflow', 'Status', 'Current Step', 'Launched By', 'Started', 'Finished'];

/** The rows of the runs of workflow template `name` that the user of `page` sees, newest first. */
export async function runRowsOf(page: Page, name: string): Promise<IndexRow[]> {
  return rowsWhere(await indexRows(page, '/workflow-job/index'), RUN_WORKFLOW_COLUMN, name);
}

/** The ID of the one run of workflow template `name` that the user of `page` sees (a seeded run). */
export async function onlyRunId(page: Page, name: string): Promise<string> {
  const rows = await runRowsOf(page, name);
  expect(rows, `one run of ${name}`).toHaveLength(1);
  return rows[0].id;
}

/**
 * Marks the open page, so that expectNotReloaded() tells whether it was loaded
 * again since: an update that should come from the poll must not need a reload.
 */
export async function markPage(page: Page): Promise<void> {
  await page.evaluate(() => {
    (window as unknown as { e2eMarked?: boolean }).e2eMarked = true;
  });
}

export async function expectNotReloaded(page: Page): Promise<void> {
  const marked = await page.evaluate(() => (window as unknown as { e2eMarked?: boolean }).e2eMarked === true);
  expect(marked, 'the page was not loaded again').toBe(true);
}

/**
 * POSTs to `path` with the session of the user of `page` and the CSRF token
 * of the page at `tokenFrom`, following the redirect: the page it leads to
 * shows the flash, so no flash is left over for the next page of the session.
 */
async function postAs(page: Page, tokenFrom: string, path: string): Promise<void> {
  const html = await (await page.request.get(tokenFrom)).text();
  const token = html.match(/<meta name="csrf-token" content="([^"]*)"/)?.[1] ?? '';
  const param = html.match(/<meta name="csrf-param" content="([^"]*)"/)?.[1] ?? '_csrf';
  expect(token, `a CSRF token on ${tokenFrom}`).not.toBe('');
  await page.request.post(path, { form: { [param]: token } });
}

/**
 * Cancels run #`runId` as the user of `page` unless it has finished: for the
 * cleanup of every spec that leaves a run waiting, so that no run waits at a
 * step of a seeded workflow for the specs after it. A run that is gone, or
 * not the user's to see, is left alone.
 */
export async function cancelRunIfActive(page: Page, runId: string): Promise<void> {
  if (!/^\d+$/.test(runId)) {
    return;
  }
  const status = await page.request.get(`/workflow-job/status?id=${runId}`);
  if (!status.ok() || ((await status.json()) as { is_finished?: boolean }).is_finished !== false) {
    return;
  }
  await postAs(page, `/workflow-job/view?id=${runId}`, `/workflow-job/cancel?id=${runId}`);
}

/**
 * Cancels job #`jobId` as the user of `page` unless it has finished, which
 * also closes its pending approval request: for the cleanup of specs that
 * leave a job waiting.
 */
export async function cancelJobIfActive(page: Page, jobId: string): Promise<void> {
  if (!/^\d+$/.test(jobId)) {
    return;
  }
  const poll = await page.request.get(`/job/log-poll?id=${jobId}&after=2147483647`);
  if (!poll.ok() || ((await poll.json()) as { finished?: boolean }).finished !== false) {
    return;
  }
  await postAs(page, `/job/view?id=${jobId}`, `/job/cancel?id=${jobId}`);
}

/** `text` as a literal in a regular expression. */
export function regexLiteral(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

import { APIRequestContext, Browser, Locator, Page, TestInfo, expect } from '@playwright/test';
import { templateId } from './helpers';
import { IndexRow, indexRows, rowsWhere } from './scoping';
import { E2E_OPERATOR, asUser } from './session';

/**
 * Helpers for the approval specs. Fixtures: commands/E2eApprovalSeeder.php.
 * e2e-admin and e2e-operator approve on e2e-approval-rule, which gates
 * e2e-gated-template and e2e-approval-workflow. The user who launches a job
 * never decides on it: when e2e-admin launches, e2e-operator decides.
 */

export const APPROVAL_RULE = 'e2e-approval-rule';
export const GATED_TEMPLATE = 'e2e-gated-template';
/** Gated by e2e-solo-approval-rule, whose only approver is e2e-admin: a launch by e2e-admin is refused. */
export const SOLO_RULE = 'e2e-solo-approval-rule';
export const SOLO_TEMPLATE = 'e2e-solo-gated-template';
/**
 * Gated by e2e-history-approval-rule. One job waits on a request that
 * expired, one was canceled while it waited, and one ran once its request
 * was approved, whose rule was deleted since.
 */
export const HISTORY_RULE = 'e2e-history-approval-rule';
export const HISTORY_TEMPLATE = 'e2e-history-gated-template';
/**
 * Gated by e2e-dual-approval-rule, which needs two approvals: e2e-admin's and
 * e2e-operator's. e2e-approval-launcher, who is neither, launches it
 * (E2E_APPROVAL_LAUNCHER), so its requests wait for both of them.
 */
export const DUAL_RULE = 'e2e-dual-approval-rule';
export const DUAL_TEMPLATE = 'e2e-dual-gated-template';

/** The disabled schedule of the soft-deleted job template e2e-deleted-template. */
export const DELETED_TEMPLATE_SCHEDULE = 'e2e-deleted-template-schedule';

/**
 * Columns of /approval/index (controllers/ApprovalController.php
 * LIST_COLUMNS): #, Job, For, Rule, Status, Requested, Expires, Resolved.
 */
export const REQUEST_JOB_COLUMN = 1;
export const REQUEST_FOR_COLUMN = 2;
export const REQUEST_RULE_COLUMN = 3;
export const REQUEST_STATUS_COLUMN = 4;
export const REQUEST_EXPIRES_COLUMN = 6;
export const REQUEST_LIST_COLUMNS = ['#', 'Job', 'For', 'Rule', 'Status', 'Requested', 'Expires', 'Resolved'];

/** controllers/ApprovalController.php EXPIRES_NEVER: the Expires cell of a pending request without a timeout. */
export const EXPIRES_NEVER = 'Never';

/** The label of a rule in the job template form and on the template page (JobTemplateRuleAssignment::label()). */
export function ruleLabel(name: string, approvals = 1): string {
  return `${name} (${approvals} approval(s))`;
}

/** services/ApprovalDecisionRefusedException.php LAUNCHER_REFUSED: the launcher of a job of a job template. */
export const LAUNCHER_REFUSED = 'You launched this job; another approver must decide.';

/**
 * services/ApprovalDecisionRefusedException.php LAUNCHER_REFUSED_RUN: the
 * launcher of a workflow run, on the request of an approval step or of a
 * gated job step.
 */
export const LAUNCHER_REFUSED_RUN = 'You launched this workflow run; another approver must decide.';

/** services/ApprovalDecisionRefusedException.php RESOLVED; the status in words: approved, rejected, timed out, canceled. */
export function resolvedMessage(requestId: string, status: string): string {
  return `Approval request #${requestId} is already resolved: ${status}.`;
}

/** services/ApprovalDecisionRefusedException.php EXPIRED */
export function expiredMessage(requestId: string): string {
  return `Approval request #${requestId} has expired and takes no more votes; its timeout is applied by the next maintenance run.`;
}

/**
 * services/ApprovalDecisionRefusedException.php JOB_NOT_WAITING: the job no
 * longer waits for approval (it left pending_approval, its job template was
 * deleted, or its workflow step or run ended).
 */
export function jobNotWaiting(requestId: string, jobId: string): string {
  return `Approval request #${requestId} can no longer be decided: its job #${jobId} no longer waits for approval.`;
}

/**
 * commands/E2eDeletedTemplateApprovalSeeder.php: soft-deleted, gated by
 * e2e-approval-rule (e2e-admin and e2e-operator approve).
 */
export const DELETED_GATE_TEMPLATE = 'e2e-deleted-gate-template';

/**
 * A job of the deleted e2e-deleted-gate-template that `launcher` launched
 * and that waits for approval, with its pending request
 * (controllers/E2eController.php actionCreateDeletedTemplateRequest,
 * YII_DEBUG only). Made on demand: the maintenance run cancels such jobs.
 */
export async function deletedTemplateRequest(
  request: APIRequestContext,
  baseURL: string | undefined,
  launcher: string,
): Promise<{ jobId: string; requestId: string }> {
  const made = await request.post(`${baseURL}/e2e/create-deleted-template-request`, { params: { launcher } });
  expect(made.status(), `create-deleted-template-request answered ${made.status()}: ${await made.text()}`).toBe(200);
  const ids = (await made.json()) as { job_id: number; request_id: number };
  expect(ids.job_id, 'the job').toBeGreaterThan(0);
  expect(ids.request_id, 'its request').toBeGreaterThan(0);
  return { jobId: String(ids.job_id), requestId: String(ids.request_id) };
}

/** services/ApprovalDecisionRefusedException.php ALREADY_VOTED */
export const ALREADY_VOTED = 'User has already voted on this request.';

/** The flash of a vote the server refused as invalid (controllers/ApprovalController.php refused()). */
export function voteNotRecorded(message: string): string {
  return `Your decision was not recorded: ${message}`;
}

/** controllers/JobTemplateController.php FLASH_WAITING_FOR_APPROVAL */
export function waitingForApproval(jobId: string): string {
  return `Job #${jobId} is waiting for approval.`;
}

/** A flash message of the layout (views/layouts/main.php). */
export function flash(page: Page, type: 'success' | 'danger' | 'warning' | 'info'): Locator {
  return page.locator(`#page-content > .alert-${type}[role="alert"]`);
}

/**
 * The row of the open approval request page whose heading is `heading`. By
 * the heading only: the For row names workflows and steps, whose names may
 * contain any heading.
 */
function requestRow(page: Page, heading: string): Locator {
  return page.locator('#page-content table').first().locator('tr')
    .filter({ has: page.locator('th', { hasText: new RegExp(`^${heading}$`) }) });
}

/** The status badge of the open approval request page. */
export function requestStatus(page: Page): Locator {
  return requestRow(page, 'Status').locator('.badge');
}

/** The Approvals cell of the open approval request page: "<counted approvals> / <required approvals>". */
export function approvalsCount(page: Page): Locator {
  return requestRow(page, 'Approvals').locator('td');
}

/** The ID of the job of the open approval request page, from its Job row. */
export async function requestJobId(page: Page): Promise<string> {
  const link = requestRow(page, 'Job').locator('td a');
  await expect(link).toHaveText(/^#\d+$/);
  return ((await link.textContent()) ?? '').trim().slice(1);
}

/** The rows of the Decisions table of the open approval request page. */
export function decisionRows(page: Page): Locator {
  return page.locator('#page-content table', { has: page.locator('th', { hasText: 'Decision' }) }).locator('tbody tr');
}

/** The Approve and Reject forms of the open approval request page. */
export function decisionForms(page: Page): Locator {
  return page.locator('#approval-approve-form, #approval-reject-form');
}

/**
 * Launches the job template `name` through its launch form as the user of
 * `page`; the ID of the new job, whose page is open then.
 */
export async function launchTemplate(page: Page, name: string): Promise<string> {
  const id = await templateId(page, name);
  await page.goto(`/job-template/launch?id=${id}`);
  await Promise.all([
    page.waitForURL(/\/job\/view\?id=\d+/),
    page.locator('#launch-form button[type="submit"]').click(),
  ]);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/**
 * The ID of the approval request of job #`jobId`, from the job page's
 * Approval Request row (#detail-approval), which links the request for a
 * user with approval.view.
 */
export async function requestOfJob(page: Page, jobId: string): Promise<string> {
  await page.goto(`/job/view?id=${jobId}`);
  const link = page.locator('#detail-approval a');
  await expect(link, `job #${jobId} links its approval request`).toHaveAttribute('href', /\/approval\/view\?id=\d+$/);
  return ((await link.getAttribute('href')) ?? '').match(/id=(\d+)$/)?.[1] ?? '';
}

/** Runs `use` with a page signed in as e2e-operator, in a browser context of its own. */
export async function asOperator<T>(browser: Browser, testInfo: TestInfo, use: (page: Page) => Promise<T>): Promise<T> {
  return asUser(browser, testInfo, E2E_OPERATOR, use);
}

/**
 * Submits the Approve or Reject form of the open request page, with
 * `comment`, and accepts its confirm dialog; the request page opens again.
 */
export async function decide(page: Page, decision: 'approve' | 'reject', comment = ''): Promise<void> {
  const form = page.locator(`#approval-${decision}-form`);
  if (comment !== '') {
    await form.locator(`#approval-${decision}-comment`).fill(comment);
  }
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForNavigation({ url: /\/approval\/view\?id=\d+/ }),
    form.locator('button[type="submit"]').click(),
  ]);
}

/** Cancels the job of the open job page through its Cancel button; the job page opens again. */
export async function cancelJob(page: Page): Promise<void> {
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForNavigation({ url: /\/job\/view\?id=\d+/ }),
    page.locator('form[action*="/job/cancel"] button[type="submit"]').click(),
  ]);
}

/** Columns of the schedule list: #, Name, Template, Cron, Timezone, Next Run, Last Run, Status, Runs as. */
export const SCHEDULE_NAME_COLUMN = 1;
export const SCHEDULE_TEMPLATE_COLUMN = 2;
export const SCHEDULE_STATUS_COLUMN = 7;
export const SCHEDULE_RUNS_AS_COLUMN = 8;

/**
 * services/ScheduleEnablement.php ENABLE_REFUSED for the schedule `name`; the
 * template's ID as a pattern. A flash carries the whitespace of its markup
 * around the message.
 */
export function scheduleEnableRefused(name: string): RegExp {
  return new RegExp(
    `^\\s*Schedule "${name}" was not enabled: its job template #\\d+ was deleted\\. `
    + 'Edit the schedule to choose another job template, or delete it\\.\\s*$',
  );
}

/** The row of the schedule `name` in the schedule list. */
export async function scheduleRow(page: Page, name: string): Promise<IndexRow> {
  const rows = rowsWhere(await indexRows(page, '/schedule/index'), SCHEDULE_NAME_COLUMN, name);
  expect(rows, `one schedule named ${name}`).toHaveLength(1);
  return rows[0];
}

/** Clicks Enable or Disable on the open schedule page and accepts the confirm dialog; the schedule list opens. */
export async function toggleOpenSchedule(page: Page): Promise<void> {
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/schedule\/index/),
    page.locator('#page-content form[action*="/schedule/toggle"] button[type="submit"]').click(),
  ]);
}

/** The approval request page shows `status` and offers neither Approve nor Reject. */
export async function expectClosedRequest(page: Page, requestId: string, status: string): Promise<void> {
  await page.goto(`/approval/view?id=${requestId}`);
  await expect(requestStatus(page)).toHaveText(status);
  await expect(decisionForms(page)).toHaveCount(0);
}

/** Columns of /audit-log/index: ID, When, User, Action, Object, IP. */
const AUDIT_USER_COLUMN = 2;
const AUDIT_ACTION_COLUMN = 3;
const AUDIT_OBJECT_COLUMN = 4;

/** One audit entry of the audit log: who wrote it, and the URL of its page. */
export interface AuditEntry {
  user: string;
  href: string;
}

/**
 * The audit entries `action` about `objectType` #`objectId`, newest first:
 * the first page of the audit log (50 entries, newest first) filtered by the
 * action and the object type. The user of `page` needs user.view
 * (e2e-admin); entries the spec just caused are on that page.
 */
export async function auditEntriesOf(page: Page, action: string, objectType: string, objectId: string): Promise<AuditEntry[]> {
  await page.goto(`/audit-log/index?action=${encodeURIComponent(action)}&object_type=${encodeURIComponent(objectType)}`);
  const rows = await page.locator('#page-content table tbody tr').evaluateAll((trs) => trs.map((tr) => ({
    href: tr.querySelector('a')?.getAttribute('href') ?? '',
    cells: Array.from(tr.querySelectorAll('td')).map((td) => (td.textContent ?? '').replace(/\s+/g, ' ').trim()),
  })));
  return rows
    .filter((row) => row.cells[AUDIT_ACTION_COLUMN] === action && row.cells[AUDIT_OBJECT_COLUMN] === `${objectType} #${objectId}`)
    .map((row) => ({ user: row.cells[AUDIT_USER_COLUMN], href: row.href }));
}

/** Who wrote the audit entries `action` about `objectType` #`objectId`, newest first (auditEntriesOf()). */
export async function auditUsersOf(page: Page, action: string, objectType: string, objectId: string): Promise<string[]> {
  return (await auditEntriesOf(page, action, objectType, objectId)).map((entry) => entry.user);
}

/** The context of the audit entry `entry`, from its page (views/audit-log/view.php); {} without one. */
export async function auditContextOf(page: Page, entry: AuditEntry): Promise<Record<string, unknown>> {
  await page.goto(entry.href);
  const context = page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Context$/ }) }).locator('pre');
  const text = (await context.count()) > 0 ? ((await context.textContent()) ?? '') : '{}';
  return JSON.parse(text) as Record<string, unknown>;
}

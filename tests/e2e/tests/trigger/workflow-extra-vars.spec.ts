import { test, expect, APIRequestContext, APIResponse } from '@playwright/test';
import { QUEUED, cancelRunIfActive, stepBadge } from '../../lib/runs';
import { asAdmin } from '../../lib/scoping';
import {
  PASSWORD_FIELD,
  PLACEHOLDER,
  TEXT_FIELD,
  expectNotInPage,
  freshAnswer,
  logPoll,
  shownExtraVars,
} from '../../lib/survey-secrets';
import { TRIGGER_HEADER, generateTriggerToken, openWorkflowTemplate, revokeTriggerTokenIfPresent } from '../../lib/triggers';
import {
  TRIGGER_VARS_FIRST,
  TRIGGER_VARS_SECOND,
  TRIGGER_VARS_WORKFLOW,
  runCount,
  runStepRow,
  stepJobId,
} from '../../lib/workflows';

/**
 * The extra vars a workflow trigger sends are the run's launch extra vars:
 * stored on the run, they reach every job step, not only the first one, and
 * a password answer stays sealed in every step's job. Extra vars that are
 * not a JSON object, or the placeholder sent as a password answer, are
 * refused with 422 before a run starts.
 *
 * Fixture: e2e-trigger-vars-workflow, the job steps e2e-trigger-vars-first
 * and e2e-trigger-vars-second on e2e-secret-template, whose survey has the
 * password field db_password (commands/E2eWorkflowGateSeeder.php,
 * E2eSurveySecretSeeder.php). No runner serves that template's runner group,
 * so the jobs stay queued: the spec cancels the first job, and the run goes
 * on with the second step. The token is generated as e2e-admin, whom the
 * trigger then runs as, and revoked afterwards. Every run a test starts is
 * canceled after it unless it has finished: its queued jobs never run.
 */
const NOT_AN_OBJECT = 'The extra vars must be a JSON object, such as {"env": "prod"}.';

let token = '';

/** The runs the running test started. */
let started: string[] = [];

/** POST /trigger/fire-workflow with the token in its header and this JSON body, if any. */
function fire(request: APIRequestContext, body?: Record<string, unknown>): Promise<APIResponse> {
  return request.post('/trigger/fire-workflow', { headers: { [TRIGGER_HEADER]: token }, ...(body === undefined ? {} : { data: body }) });
}

async function launchedRunId(response: APIResponse): Promise<number> {
  expect(response.status(), await response.text()).toBe(201);
  const body = (await response.json()) as { workflow_job_id?: number };
  expect(body.workflow_job_id).toBeGreaterThan(0);
  started.push(String(body.workflow_job_id ?? ''));
  return body.workflow_job_id ?? 0;
}

test.describe('Workflow trigger extra vars', () => {
  test.beforeAll(async ({ browser }, testInfo) => {
    await asAdmin(browser, testInfo, async (admin) => {
      await openWorkflowTemplate(admin, TRIGGER_VARS_WORKFLOW);
      token = await generateTriggerToken(admin, 'wf-');
    });
  });

  test.afterEach(async ({ page }) => {
    for (const runId of started) {
      await cancelRunIfActive(page, runId);
    }
    started = [];
  });

  test.afterAll(async ({ browser }, testInfo) => {
    await asAdmin(browser, testInfo, async (admin) => {
      await openWorkflowTemplate(admin, TRIGGER_VARS_WORKFLOW);
      await revokeTriggerTokenIfPresent(admin);
    });
  });

  // Regression: the trigger's extra vars reached only the start step.
  test('the extra vars reach every job step, and a password answer stays sealed', async ({ page, request }, testInfo) => {
    const marker = `e2e-trigger-var-${Date.now().toString(36)}`;
    const answer = freshAnswer('workflow-trigger');
    const runId = await launchedRunId(await fire(request, {
      extra_vars: { e2e_marker: marker, [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: answer },
    }));
    const expected = { e2e_marker: marker, [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER };

    // The run shows what it was launched with, the password answer as the placeholder.
    await page.goto(`/workflow-job/view?id=${runId}`);
    expect(JSON.parse((await page.locator('#wj-extra-vars').textContent()) ?? '')).toEqual(expected);
    await expectNotInPage(page, answer);
    await expect(page.locator('#wj-steps-table thead th')).toHaveText(['#', 'Step', 'Type', 'Status', 'Job / Request', 'Started', 'Duration', 'Finished']);
    const first = runStepRow(page, TRIGGER_VARS_FIRST);
    await expect(first.locator('[data-wjs-type-cell]')).toHaveText('Job');
    // No runner serves the template's group: the step's job waits for one.
    await expect(stepBadge(first)).toHaveText(QUEUED);
    const firstJob = await stepJobId(first);
    await expect(runStepRow(page, TRIGGER_VARS_SECOND)).toHaveCount(0);

    // The first step's job got them.
    const jobPage = await page.context().newPage();
    await jobPage.goto(`/job/view?id=${firstJob}`);
    expect(await shownExtraVars(jobPage)).toEqual(expected);
    await expectNotInPage(jobPage, answer);
    // No runner serves its group: canceling it fails the step, and the run
    // goes on with the next step (no On Failure branch).
    jobPage.once('dialog', (dialog) => dialog.accept());
    await jobPage.locator('form[action*="/job/cancel"] button[type="submit"]').click();
    await expect(jobPage.locator('#status-badge')).toHaveText('Canceled');

    // The open run page polls while the run is unfinished: it adds the row
    // of the second step, keyed by its execution, without a reload. While
    // nothing changes it polls less and less often (3, 6, 12, 24, then
    // every 30 seconds: web/js/workflow-run-live.js), so the row can take
    // up to about 30 seconds to show.
    const second = runStepRow(page, TRIGGER_VARS_SECOND);
    testInfo.setTimeout(testInfo.timeout + 35_000);
    await expect(second).toHaveCount(1, { timeout: 35_000 });
    await expect(second.locator('[data-wjs-type-cell]')).toHaveText('Job');
    await expect(stepBadge(second)).toHaveText(QUEUED);
    await expect(stepBadge(first)).toHaveText('Failed');
    await expect(page.locator('#wj-steps-table tbody tr')).toHaveCount(2);
    await expect(second).toHaveAttribute('data-wjs-id', /^\d+$/);
    expect(await second.getAttribute('data-wjs-id')).not.toBe(await first.getAttribute('data-wjs-id'));
    await expect(page.locator('#wj-status')).toHaveText(QUEUED);

    // The second step's job got the run's extra vars too, the answer sealed.
    const secondJob = await stepJobId(second);
    await jobPage.goto(`/job/view?id=${secondJob}`);
    expect(await shownExtraVars(jobPage)).toEqual(expected);
    await expectNotInPage(jobPage, answer);
    expect((await logPoll(jobPage, secondJob)).raw).not.toContain(answer);
    await jobPage.close();

    // After a reload the page renders both rows itself.
    await page.reload();
    await expect(page.locator('#wj-steps-table tbody tr')).toHaveCount(2);
    await expect(runStepRow(page, TRIGGER_VARS_SECOND).locator('[data-wjs-job-link]')).toHaveText(`#${secondJob}`);
    await expectNotInPage(page, answer);
  });

  test('a fire without a body launches a run without extra vars', async ({ page, request }) => {
    const runId = await launchedRunId(await fire(request));
    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(page.locator('#wj-steps-table')).toContainText(TRIGGER_VARS_FIRST);
    await expect(page.locator('#wj-extra-vars')).toHaveCount(0);
  });

  test('extra vars that are not a JSON object answer 422, and no run starts', async ({ page, request }) => {
    const before = await runCount(page, TRIGGER_VARS_WORKFLOW);
    for (const extraVars of [7, ['env', 'prod'], 'prod', true]) {
      const response = await fire(request, { extra_vars: extraVars });
      expect(response.status(), JSON.stringify(extraVars)).toBe(422);
      expect(await response.json(), JSON.stringify(extraVars)).toEqual({ error: NOT_AN_OBJECT });
    }
    expect(await runCount(page, TRIGGER_VARS_WORKFLOW)).toBe(before);
  });

  test('the placeholder as the password answer of a job step answers 422, and no run starts', async ({ page, request }) => {
    const before = await runCount(page, TRIGGER_VARS_WORKFLOW);
    const response = await fire(request, { extra_vars: { [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER } });
    expect(response.status()).toBe(422);
    expect(await response.json()).toEqual({
      error: `The value of "${PASSWORD_FIELD}" is the placeholder ${PLACEHOLDER}; send the real value.`,
    });
    expect(await runCount(page, TRIGGER_VARS_WORKFLOW)).toBe(before);
  });
});

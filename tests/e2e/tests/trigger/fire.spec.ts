import { test, expect, APIResponse, Browser, TestInfo } from '@playwright/test';
import { LIMIT_TOO_LONG, VERBOSITY_TOO_HIGH } from '../../lib/job-templates';
import { asUser, E2E_ADMIN } from '../../lib/session';
import {
  PASSWORD_FIELD,
  PLACEHOLDER,
  SECRET_TEMPLATE,
  SECRET_WORKFLOW,
  TEXT_FIELD,
  expectNotInPage,
  freshAnswer,
  logPoll,
  shownExtraVars,
} from '../../lib/survey-secrets';
import {
  TRIGGER_HEADER,
  generateTriggerToken,
  openJobTemplate,
  openWorkflowTemplate,
  revokeTriggerTokenIfPresent,
} from '../../lib/triggers';

/**
 * Inbound triggers, called the way an external system calls them: without a
 * session (this spec runs in the unauthenticated project). The token belongs
 * in the X-Trigger-Token header; `Authorization: Bearer` works too, and the
 * URL forms (?token= and /trigger/<token>) keep working but are deprecated,
 * because URLs end up in access logs.
 *
 * The spec generates the tokens it fires as e2e-admin, on fixtures of
 * commands/E2eSurveySecretSeeder.php, and revokes them afterwards. The jobs
 * it launches stay queued: no runner serves the template's runner group.
 */

/** 64 hex characters that are no template's token. */
const UNKNOWN_TOKEN = '0123456789abcdef'.repeat(4);

let jobToken = '';
let workflowToken = '';

async function launchedJobId(response: APIResponse): Promise<number> {
  expect(response.status(), await response.text()).toBe(201);
  const body = (await response.json()) as { job_id?: number };
  expect(body.job_id).toBeGreaterThan(0);
  return body.job_id ?? 0;
}

async function launchedWorkflowJobId(response: APIResponse): Promise<number> {
  expect(response.status(), await response.text()).toBe(201);
  const body = (await response.json()) as { workflow_job_id?: number };
  expect(body.workflow_job_id).toBeGreaterThan(0);
  return body.workflow_job_id ?? 0;
}

/** The context of the newest audit entry with this action, read as e2e-admin. */
async function newestAuditContext(browser: Browser, testInfo: TestInfo, action: string): Promise<{ raw: string; context: Record<string, unknown> }> {
  return asUser(browser, testInfo, E2E_ADMIN, async (admin) => {
    await admin.goto(`/audit-log/index?action=${encodeURIComponent(action)}`);
    await admin.locator('#page-content table tbody tr a').first().click();
    const raw = (await admin.locator('.card', { hasText: 'Context' }).locator('pre').textContent()) ?? '';
    return { raw, context: JSON.parse(raw) as Record<string, unknown> };
  });
}

test.describe('Inbound triggers', () => {
  test.beforeAll(async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (admin) => {
      await openJobTemplate(admin, SECRET_TEMPLATE);
      jobToken = await generateTriggerToken(admin, '');
      await openWorkflowTemplate(admin, SECRET_WORKFLOW);
      workflowToken = await generateTriggerToken(admin, 'wf-');
    });
  });

  test.afterAll(async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (admin) => {
      await openJobTemplate(admin, SECRET_TEMPLATE);
      await revokeTriggerTokenIfPresent(admin);
      await openWorkflowTemplate(admin, SECRET_WORKFLOW);
      await revokeTriggerTokenIfPresent(admin);
    });
  });

  test('a job trigger fires with the token in the X-Trigger-Token header', async ({ request }) => {
    await launchedJobId(await request.post('/trigger/fire', { headers: { [TRIGGER_HEADER]: jobToken } }));
  });

  test('a job trigger fires with the token as Authorization: Bearer', async ({ request }) => {
    await launchedJobId(await request.post('/trigger/fire', { headers: { Authorization: `Bearer ${jobToken}` } }));
  });

  test('a job trigger still fires with the deprecated token query parameter', async ({ request }) => {
    await launchedJobId(await request.post('/trigger/fire', { params: { token: jobToken } }));
  });

  test('a job trigger still fires with the deprecated token path, with and without a trailing slash', async ({ request }) => {
    await launchedJobId(await request.post(`/trigger/${jobToken}`));
    await launchedJobId(await request.post(`/trigger/${jobToken}/`));
  });

  test('a workflow trigger fires with the header, with Bearer and with the deprecated query parameter', async ({ request }) => {
    await launchedWorkflowJobId(await request.post('/trigger/fire-workflow', { headers: { [TRIGGER_HEADER]: workflowToken } }));
    await launchedWorkflowJobId(await request.post('/trigger/fire-workflow', { headers: { Authorization: `Bearer ${workflowToken}` } }));
    await launchedWorkflowJobId(await request.post('/trigger/fire-workflow', { params: { token: workflowToken } }));
  });

  test('a call without a usable token answers 400', async ({ request }) => {
    for (const path of ['/trigger/fire', '/trigger/fire-workflow', '/trigger/fire?token=', '/trigger/fire?token%5B%5D=x']) {
      const response = await request.post(path);
      expect(response.status(), path).toBe(400);
      expect(await response.text(), path).toContain('Missing trigger token.');
    }
    // Basic credentials are no trigger token.
    const basic = await request.post('/trigger/fire', { headers: { Authorization: 'Basic ZTJlOmUyZQ==' } });
    expect(basic.status()).toBe(400);
  });

  test('an unknown token answers 404, and other trigger paths do too', async ({ request }) => {
    expect((await request.post('/trigger/fire', { headers: { [TRIGGER_HEADER]: UNKNOWN_TOKEN } })).status()).toBe(404);
    expect((await request.post('/trigger/fire-workflow', { headers: { [TRIGGER_HEADER]: UNKNOWN_TOKEN } })).status()).toBe(404);
    expect((await request.post(`/trigger/${UNKNOWN_TOKEN}`)).status()).toBe(404);
    expect((await request.post('/trigger/fire/extra')).status()).toBe(404);
    expect((await request.post('/trigger/not-a-token')).status()).toBe(404);
  });

  test('a call that is not a POST answers 405', async ({ request }) => {
    expect((await request.get(`/trigger/${UNKNOWN_TOKEN}`)).status()).toBe(405);
    expect((await request.get('/trigger/fire', { headers: { [TRIGGER_HEADER]: jobToken } })).status()).toBe(405);
    expect((await request.get('/trigger/fire-workflow', { headers: { [TRIGGER_HEADER]: workflowToken } })).status()).toBe(405);
  });

  test('the placeholder as a password answer answers 422 with the reason', async ({ request }) => {
    const response = await request.post('/trigger/fire', {
      headers: { [TRIGGER_HEADER]: jobToken },
      data: { extra_vars: { [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER } },
    });
    expect(response.status()).toBe(422);
    expect(await response.json()).toEqual({
      error: `The value of "${PASSWORD_FIELD}" is the placeholder ${PLACEHOLDER}; send the real value.`,
    });
  });

  // Regression: a verbosity or a limit the job record refuses failed the
  // launch only when the record was saved, so the trigger answered 500
  // "Launch failed." for input its caller can fix. It is refused before the
  // launch: 422 with the record's message (services/JobLaunchInput.php).
  test('a verbosity or a limit the job record refuses answers 422 with the reason', async ({ request }) => {
    const verbosity = await request.post('/trigger/fire', { headers: { [TRIGGER_HEADER]: jobToken }, data: { verbosity: 9 } });
    expect(verbosity.status()).toBe(422);
    expect(await verbosity.json()).toEqual({ error: VERBOSITY_TOO_HIGH });

    const limit = await request.post('/trigger/fire', { headers: { [TRIGGER_HEADER]: jobToken }, data: { limit: 'web:'.repeat(64) + 'x' } });
    expect(limit.status()).toBe(422);
    expect(await limit.json()).toEqual({ error: LIMIT_TOO_LONG });
  });

  test('a password answer sent to a trigger is stored encrypted', async ({ request, browser }, testInfo) => {
    const answer = freshAnswer('trigger');
    const jobId = await launchedJobId(await request.post('/trigger/fire', {
      headers: { [TRIGGER_HEADER]: jobToken },
      data: { extra_vars: { [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: answer } },
    }));

    await asUser(browser, testInfo, E2E_ADMIN, async (admin) => {
      await admin.goto(`/job/view?id=${jobId}`);
      expect(await shownExtraVars(admin)).toEqual({ [TEXT_FIELD]: 'qa', [PASSWORD_FIELD]: PLACEHOLDER });
      await expectNotInPage(admin, answer);
      expect((await logPoll(admin, String(jobId))).raw).not.toContain(answer);
    });
  });

  // Operators find callers that still send the token in the URL by the
  // transport of their trigger.fired entries.
  test('every call records how the token was sent, never the token', async ({ request, browser }, testInfo) => {
    const calls: [string, () => Promise<APIResponse>][] = [
      ['header', () => request.post('/trigger/fire', { headers: { [TRIGGER_HEADER]: jobToken } })],
      ['bearer', () => request.post('/trigger/fire', { headers: { Authorization: `Bearer ${jobToken}` } })],
      ['url', () => request.post(`/trigger/${jobToken}`)],
    ];
    for (const [transport, send] of calls) {
      const jobId = await launchedJobId(await send());
      const audit = await newestAuditContext(browser, testInfo, 'trigger.fired');
      expect(audit.context, transport).toEqual({ transport, job_id: jobId });
      expect(audit.raw, transport).not.toContain(jobToken);
    }
  });
});

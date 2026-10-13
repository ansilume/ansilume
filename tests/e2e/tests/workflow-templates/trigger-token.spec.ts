import { test, expect } from '@playwright/test';
import { expectNotInPage } from '../../lib/survey-secrets';
import { TRIGGER_HEADER, curlExample, generateTriggerToken, lastCopied, openWorkflowTemplate, recordCopies } from '../../lib/triggers';

// Inbound-trigger spec for workflow templates. Mirrors the job-template
// trigger flow:
//   1. Operator visits /workflow-template/view?id=N
//   2. Clicks "Generate Token" — the raw value is shown once, in the
//      X-Trigger-Token header form of the card, never in a URL
//   3. Captures the token and POSTs to /trigger/fire-workflow with the header
//   4. Endpoint returns 201 + workflow_job_id
//   5. Cleanup: revokes the token so it can't accidentally be reused
//
// Pinned here because the JSON contract (workflow_job_id field, 201
// status) is what external systems will hard-code. Every transport and error
// answer: tests/trigger/fire.spec.ts.

test.describe('Workflow template inbound trigger', () => {
  test('generate token, fire workflow via /trigger/fire-workflow with the header, revoke', async ({ page, request, baseURL }) => {
    await recordCopies(page);
    // The seeded workflow template's page, found by its exact name on any
    // page of the index: more fixtures push it off the first one.
    await openWorkflowTemplate(page, 'e2e-workflow');

    // The "Inbound Trigger" card must be visible.
    const card = page.locator('.card', { hasText: 'Inbound Trigger' });
    await expect(card).toBeVisible({ timeout: 5_000 });

    // Generate a fresh token (revoking one a previous run left). The
    // post-redirect view shows the raw value once.
    const rawToken = await generateTriggerToken(page, 'wf-');

    // Regression: the card showed the token in URLs, which end up in access
    // logs. It shows the header form for the workflow endpoint instead.
    const html = await page.content();
    expect(html, 'no URL with the token').not.toContain(`token=${rawToken}`);
    await expect(page.locator('.alert-trigger_token_raw')).toHaveCount(0);
    const endpoint = ((await page.locator('#wf-trigger-endpoint').textContent()) ?? '').trim();
    expect(endpoint).toMatch(/^https?:\/\/[^/]+\/trigger\/fire-workflow$/);
    const curl = curlExample(rawToken, endpoint);
    await expect(page.locator('#wf-trigger-curl-example')).toHaveText(curl);
    const note = page.locator('#wf-trigger-transport-note');
    await expect(note).toContainText('that form is deprecated');
    // Regression: the note named /trigger/<token> too, which reaches job
    // templates only; a workflow token sent there is a 404.
    await expect(note).toContainText('(?token=…) keep working');
    await expect(note).not.toContainText('/trigger/<token>');
    await page.locator('#btn-copy-wf-curl').click();
    await expect(page.locator('#btn-copy-wf-curl')).toHaveText('Copied!');
    expect(await lastCopied(page)).toBe(curl);
    // The body's extra vars reach every job step of the run, not only the
    // first (trigger/workflow-extra-vars.spec.ts fires with them).
    await expect(page.locator('#wf-trigger-extra-vars-note')).toHaveText(
      'The body\'s extra_vars reach every job step of the run: they win over a job template\'s own extra vars, '
        + 'and a step\'s variable mapping wins over them.',
    );

    // Fire the workflow through the public trigger endpoint. The token is
    // the credential here; the request fixture's session cookies don't
    // change behaviour because TriggerController has no auth filter.
    const response = await request.post(`${baseURL}/trigger/fire-workflow`, {
      headers: { [TRIGGER_HEADER]: rawToken },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as { workflow_job_id?: number };
    expect(typeof body.workflow_job_id).toBe('number');
    expect(body.workflow_job_id).toBeGreaterThan(0);

    // Shown once: after a reload the card masks the token.
    await page.reload();
    await expect(page.locator('#wf-trigger-curl-masked')).toHaveText(curlExample('*'.repeat(16), endpoint));
    await expectNotInPage(page, rawToken);

    // Cleanup so the seeded template stays in a known state across runs.
    // The revoke form has an onsubmit=confirm() — accept it.
    page.once('dialog', (d) => d.accept());
    await page.locator('form[action*="/revoke-trigger-token"] button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('button:has-text("Generate Token")')).toBeVisible({ timeout: 5_000 });
  });

  test('unknown token returns 404 from /trigger/fire-workflow', async ({ request, baseURL }) => {
    const response = await request.post(`${baseURL}/trigger/fire-workflow`, {
      headers: { [TRIGGER_HEADER]: 'definitely-not-a-real-token-1234567890abcdef' },
    });
    expect(response.status()).toBe(404);
  });
});

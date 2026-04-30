import { test, expect } from '@playwright/test';

// Inbound-trigger spec for workflow templates. Mirrors the job-template
// trigger flow:
//   1. Operator visits /workflow-template/view?id=N
//   2. Clicks "Generate Token" — the raw value is shown once in a flash
//   3. Captures the token and POSTs to /trigger/fire-workflow?token=…
//   4. Endpoint returns 201 + workflow_job_id
//   5. Cleanup: revokes the token so it can't accidentally be reused
//
// Pinned here because the JSON contract (workflow_job_id field, 201
// status) is what external systems will hard-code.

test.describe('Workflow template inbound trigger', () => {
  test('generate token, fire workflow via /trigger/fire-workflow, revoke', async ({ page, request, baseURL }) => {
    // Navigate to the seeded workflow template's view page. Use the
    // exact-match link to avoid matching e2e-paused-workflow / e2e-approval-workflow.
    await page.goto('/workflow-template/index?sort=-id');
    await page.getByRole('link', { name: 'e2e-workflow', exact: true }).first().click();
    await page.waitForLoadState('domcontentloaded');

    // The "Inbound Trigger" card must be visible.
    const card = page.locator('.card', { hasText: 'Inbound Trigger' });
    await expect(card).toBeVisible({ timeout: 5_000 });

    // If a previous run left a token in place, revoke it first so we land
    // back at the "Generate Token" button.
    if (await card.locator('button:has-text("Revoke Token")').isVisible({ timeout: 1_000 }).catch(() => false)) {
      page.once('dialog', (d) => d.accept());
      await card.locator('button:has-text("Revoke Token")').click();
      await page.waitForLoadState('domcontentloaded');
    }

    // Generate a fresh token. The post-redirect view shows the raw value once.
    await card.locator('button:has-text("Generate Token")').click();
    await page.waitForLoadState('domcontentloaded');

    const tokenCode = page.locator('#wf-trigger-token-display');
    await expect(tokenCode).toBeVisible({ timeout: 5_000 });
    const rawToken = (await tokenCode.textContent())?.trim() ?? '';
    expect(rawToken.length).toBe(64);

    // Fire the workflow through the public trigger endpoint. The token is
    // the credential here; the request fixture's session cookies don't
    // change behaviour because TriggerController has no auth filter.
    const response = await request.post(`${baseURL}/trigger/fire-workflow`, {
      params: { token: rawToken },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as { workflow_job_id?: number };
    expect(typeof body.workflow_job_id).toBe('number');
    expect(body.workflow_job_id).toBeGreaterThan(0);

    // Cleanup so the seeded template stays in a known state across runs.
    // The revoke form has an onsubmit=confirm() — accept it.
    page.once('dialog', (d) => d.accept());
    await page.locator('form[action*="/revoke-trigger-token"] button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('button:has-text("Generate Token")')).toBeVisible({ timeout: 5_000 });
  });

  test('unknown token returns 404 from /trigger/fire-workflow', async ({ request, baseURL }) => {
    const response = await request.post(`${baseURL}/trigger/fire-workflow`, {
      params: { token: 'definitely-not-a-real-token-1234567890abcdef' },
    });
    expect([401, 403, 404]).toContain(response.status());
  });
});

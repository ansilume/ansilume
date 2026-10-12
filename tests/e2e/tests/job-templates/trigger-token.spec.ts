import { test, expect } from '@playwright/test';
import { idWhere, indexRows } from '../../lib/scoping';
import { SECRET_TEMPLATE, expectNotInPage } from '../../lib/survey-secrets';
import {
  TRIGGER_HEADER,
  curlExample,
  generateTriggerToken,
  lastCopied,
  openJobTemplate,
  recordCopies,
  revokeTriggerTokenIfPresent,
} from '../../lib/triggers';

/**
 * The Inbound Trigger card of a job template page is shown to users who may
 * change the template. e2e-admin may change every template, also one of a
 * project that a team only views: e2e-alpha-viewed-tmpl, whose trigger token
 * e2e-admin generated (commands/E2eTeamScopingSeeder.php). The operator and
 * viewer side: tests/team-scoping/triggers.spec.ts.
 *
 * The card shows how to send the token in the X-Trigger-Token header, never a
 * URL with the token in it: URLs end up in access logs. Firing a trigger:
 * tests/trigger/fire.spec.ts.
 */
test.describe('Job Template Trigger Token', () => {
  test('admin sees the trigger card and whom the trigger runs as', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/job-template/index?sort=name'), 1, 'e2e-alpha-viewed-tmpl');
    await page.goto(`/job-template/view?id=${id}`);

    await expect(page.locator('#page-content .card', { hasText: 'Inbound Trigger' })).toHaveCount(1);
    await expect(page.getByTestId('trigger-runs-as')).toContainText('Runs as e2e-admin, who generated the token.');
    await expect(page.locator('#page-content form[action*="/revoke-trigger-token"] button')).toBeVisible();
  });

  test('admin sees a new token once, only in the header form, and the copy buttons copy it', async ({ page }) => {
    await recordCopies(page);
    await openJobTemplate(page, SECRET_TEMPLATE);
    const token = await generateTriggerToken(page, '');

    // Regression: the card showed URLs with the token in them (they end up in
    // access logs), and the layout printed the token a second time.
    const html = await page.content();
    expect(html, 'no URL with the token').not.toContain(`token=${token}`);
    expect(html, 'no URL with the token').not.toContain(`/trigger/${token}`);
    await expect(page.locator('.alert-trigger_token_raw')).toHaveCount(0);
    await expect(page.locator('.alert', { hasText: token })).toHaveCount(1);

    const endpoint = ((await page.locator('#trigger-endpoint').textContent()) ?? '').trim();
    expect(endpoint).toMatch(/^https?:\/\/[^/]+\/trigger\/fire$/);
    const curl = curlExample(token, endpoint);
    await expect(page.locator('#trigger-curl-example')).toHaveText(curl);
    const note = page.locator('#trigger-transport-note');
    await expect(note).toContainText('Authorization: Bearer <token> works as well.');
    await expect(note).toContainText('that form is deprecated');
    // The path form /trigger/<token> reaches job templates, so this card names it.
    await expect(note).toContainText('(?token=… or /trigger/<token>) keep working');

    // The cURL example holds quotes, which would end a JavaScript literal in
    // onclick: the buttons read their text from data-copy-text, exactly.
    await page.locator('#btn-copy-trigger-token').click();
    await expect(page.locator('#btn-copy-trigger-token')).toHaveText('Copied!');
    expect(await lastCopied(page)).toBe(token);
    await page.locator('#btn-copy-curl').click();
    await expect(page.locator('#btn-copy-curl')).toHaveText('Copied!');
    expect(await lastCopied(page)).toBe(curl);

    // What the card shows works: a POST to its endpoint with the token in the header.
    const fired = await page.request.post(new URL(endpoint).pathname, { headers: { [TRIGGER_HEADER]: token } });
    expect(fired.status()).toBe(201);

    // Shown once: afterwards the card shows the header form with a masked token.
    await page.reload();
    await expect(page.locator('#trigger-token-display')).toHaveCount(0);
    await expect(page.locator('#trigger-curl-masked')).toHaveText(curlExample('*'.repeat(16), endpoint));
    await expectNotInPage(page, token);

    await revokeTriggerTokenIfPresent(page);
    await expect(page.getByTestId('trigger-not-configured')).toBeVisible();
  });
});

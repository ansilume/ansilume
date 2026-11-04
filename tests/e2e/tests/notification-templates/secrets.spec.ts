import { test, expect, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { submitForm } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';
import { generateApiToken, revokeApiToken } from '../../lib/tokens';

/** components/SurveySecrets.php PLACEHOLDER: what the page and the edit form show for a channel secret. */
const PLACEHOLDER = '$encrypted$';

/** components/NotificationConfigSecrets.php FORM_HINT */
const FORM_HINT = `Secrets show as ${PLACEHOLDER}: leave it to keep the stored value, or replace it to change it.`;

/** components/NotificationConfigSecrets.php URL_CHANGED, for the header Authorization after a new url. */
const URL_CHANGED = 'headers.Authorization: url changed; enter the real value.';

/** components/NotificationConfigSecrets.php URL_CHANGED, for the signing secret of a webhook after a new url. */
const SIGNING_SECRET_URL_CHANGED = 'signing_secret: url changed; enter the real value.';

/** services/notification/NotificationTemplateFormView.php HEADERS_FORM: the form's own refusal, before anything is sent. */
const HEADERS_FORM = 'Custom Headers must be a JSON object of header names and values, such as {"X-Custom": "value"}.';

/**
 * Mailhog's web port answers every POST with 404 "404 page not found": a test
 * notification to it fails with that status (SlackChannel, WebhookChannel),
 * which shows the URL the channel used. A URL that is the placeholder is no
 * http or https URL, which the channel refuses (URL_REFUSED).
 */
const MAILHOG = 'http://mailhog:8025';

/** components/OutboundHttpException.php SCHEME_REFUSED, after the channel's name. */
const URL_REFUSED = 'The URL must use http or https.';

const VIEW_PAGE = /\/notification-template\/view\?id=(\d+)/;

/** The id of the open notification template page. */
function openId(page: Page): string {
  const id = page.url().match(VIEW_PAGE)?.[1];
  expect(id, 'a notification template page is open').toBeTruthy();
  return id ?? '';
}

/** Fills the body template, whose textarea a CodeMirror editor hides, and ticks one event. */
async function fillBodyAndEvent(page: Page): Promise<void> {
  await page.locator('textarea[data-template-editor]').evaluate((el) => {
    (el as HTMLTextAreaElement).value = 'E2E {{ event }}';
  });
  await page.locator('.event-checkbox').first().check();
}

/** Clicks Send Test on the open notification template page; the page opens again with the result. */
async function sendTest(page: Page): Promise<void> {
  await Promise.all([
    page.waitForNavigation({ url: VIEW_PAGE }),
    page.locator('#page-content form[action*="/notification-template/test"] button[type="submit"]').click(),
  ]);
}

async function deleteTemplate(page: Page, id: string): Promise<void> {
  await page.goto(`/notification-template/view?id=${id}`);
  await forgePost(page, `/notification-template/delete?id=${id}`, {}, /\/notification-template\/index/);
}

/** How often `secret` occurs in the HTML of the open page: its text, attributes and form values. */
async function occurrences(page: Page, secret: string): Promise<number> {
  return (await page.content()).split(secret).length - 1;
}

/** The config the open template page shows, parsed: its keys in any order. */
async function shownConfig(page: Page): Promise<unknown> {
  return JSON.parse((await page.getByTestId('notification-template-config').textContent()) ?? '');
}

/**
 * The channel secrets of a notification template are shown as the
 * placeholder on its page and in its edit form; saving the form unchanged
 * keeps the stored values.
 */
test.describe('Notification Template secrets', () => {
  test('the edit form shows the placeholder, and saving it unchanged keeps the stored webhook URL', async ({ page }) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-nt-secret-slack-${suffix}`;
    // A fixture, not a secret: what no page may show.
    const webhookUrl = `${MAILHOG}/e2e-nt-secret-${suffix}`;

    await page.goto('/notification-template/create');
    await page.locator('#notificationtemplate-name').fill(name);
    await page.locator('#nt-channel').selectOption('slack');
    await page.locator('#slack-webhook-url').fill(webhookUrl);
    await fillBodyAndEvent(page);
    await submitForm(page);
    await expect(flash(page, 'success')).toContainText(`Notification template "${name}" created.`);
    const id = openId(page);

    try {
      await expect(page.getByTestId('notification-template-config')).toHaveText(`{"webhook_url":"${PLACEHOLDER}"}`);
      expect(await occurrences(page, `e2e-nt-secret-${suffix}`), 'the stored URL in the page').toBe(0);

      // Baseline: the channel posts to the stored URL, which answers 404. The
      // flash names the status only, never the answer ("404 page not found").
      await sendTest(page);
      await expect(flash(page, 'danger')).toHaveText('Test notification failed: SlackChannel: HTTP 404.');

      await page.locator('#page-content a:has-text("Edit")').click();
      await expect(page.locator('#slack-webhook-url')).toHaveValue(PLACEHOLDER);
      await expect(page.locator('#nt-config')).toHaveValue(`{"webhook_url":"${PLACEHOLDER}"}`);
      await expect(page.getByTestId('notification-template-secret-hint')).toHaveText(FORM_HINT);
      expect(await occurrences(page, `e2e-nt-secret-${suffix}`), 'the stored URL in the edit form').toBe(0);

      await page.locator('#notificationtemplate-description').fill('Saved with the placeholder');
      await submitForm(page);
      await expect(flash(page, 'success')).toContainText(`Notification template "${name}" updated.`);
      await expect(page.getByTestId('notification-template-config')).toHaveText(`{"webhook_url":"${PLACEHOLDER}"}`);

      // The stored URL was kept: the channel still posts to it, not to the placeholder.
      await sendTest(page);
      await expect(flash(page, 'danger')).toHaveText('Test notification failed: SlackChannel: HTTP 404.');
      await expect(flash(page, 'danger')).not.toContainText(URL_REFUSED);
    } finally {
      await deleteTemplate(page, id);
    }
  });

  test('a header keeps its stored value only while the URL keeps its own', async ({ page }) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-nt-secret-webhook-${suffix}`;
    const headerToken = `Bearer e2e-header-${suffix}`;

    await page.goto('/notification-template/create');
    await page.locator('#notificationtemplate-name').fill(name);
    await page.locator('#nt-channel').selectOption('webhook');
    await page.locator('#webhook-url').fill(`${MAILHOG}/e2e-nt-webhook-${suffix}`);
    await page.locator('#webhook-headers').fill(JSON.stringify({ Authorization: headerToken }));
    await fillBodyAndEvent(page);
    await submitForm(page);
    await expect(flash(page, 'success')).toContainText(`Notification template "${name}" created.`);
    const id = openId(page);

    try {
      await expect(page.getByTestId('notification-template-config'))
        .toHaveText(`{"url":"${PLACEHOLDER}","headers":{"Authorization":"${PLACEHOLDER}"}}`);
      expect(await occurrences(page, headerToken), 'the header token in the page').toBe(0);

      await page.locator('#page-content a:has-text("Edit")').click();
      await expect(page.locator('#webhook-url')).toHaveValue(PLACEHOLDER);
      await expect(page.locator('#webhook-headers')).toHaveValue(`{"Authorization":"${PLACEHOLDER}"}`);
      expect(await occurrences(page, headerToken), 'the header token in the edit form').toBe(0);

      // Another URL with the stored header: refused, the header must be entered again.
      await page.locator('#webhook-url').fill('https://collector.example.net/e2e');
      await submitForm(page);
      await expect(page.locator('.field-nt-config')).toContainText(URL_CHANGED);
      expect(await occurrences(page, headerToken), 'the header token after the refusal').toBe(0);

      await page.goto(`/notification-template/view?id=${id}`);
      await expect(page.getByTestId('notification-template-config'))
        .toHaveText(`{"url":"${PLACEHOLDER}","headers":{"Authorization":"${PLACEHOLDER}"}}`);
      await sendTest(page);
      await expect(flash(page, 'danger')).toHaveText('Test notification failed: WebhookChannel: HTTP 404.');
    } finally {
      await deleteTemplate(page, id);
    }
  });

  // Regression: the webhook panel's script built the config from the URL and
  // the headers only, so a web save of a template whose signing secret and
  // payload the API had set dropped both, and the template sent unsigned
  // template payloads from then on. A new URL was refused for the stored
  // signing secret, and the form had no field to enter it again. The class:
  // a key the panel does not show is kept, and headers that are no JSON
  // object are refused instead of dropped.
  test('a web save keeps the signing secret and the payload the API set, and a new URL takes the signing secret again', async ({ page, request, baseURL }) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-nt-signed-webhook-${suffix}`;
    // Fixtures, not secrets: what no page may show.
    const signingSecret = `e2e-signing-secret-${suffix}`;
    const rotatedSecret = `e2e-rotated-signing-secret-${suffix}`;
    const tokenName = `e2e-nt-signed-webhook-${suffix}`;

    const token = await generateApiToken(page, tokenName);
    let id = '';
    try {
      const created = await request.post(`${baseURL}/api/v1/notification-templates`, {
        headers: { Authorization: `Bearer ${token}` },
        data: {
          name,
          channel: 'webhook',
          events: ['job.failed'],
          // receiver_note: a key no panel shows, which the channel ignores.
          config: { url: `${MAILHOG}/e2e-nt-signed-${suffix}`, signing_secret: signingSecret, payload: 'event', receiver_note: 'kept' },
          subject_template: 'E2E {{ event }}',
          body_template: 'E2E {{ event }}',
        },
      });
      expect(created.status(), `POST /api/v1/notification-templates answered ${created.status()}`).toBe(201);
      id = String(((await created.json()) as { data: { id: number } }).data.id);
    } finally {
      await revokeApiToken(page, tokenName);
    }

    try {
      const saved = { url: PLACEHOLDER, signing_secret: PLACEHOLDER, payload: 'event', receiver_note: 'kept' };
      await page.goto(`/notification-template/view?id=${id}`);
      expect(await shownConfig(page)).toEqual(saved);

      await page.locator('#page-content a:has-text("Edit")').click();
      await page.locator('#notificationtemplate-description').fill('Saved in the form');
      await submitForm(page);
      await expect(flash(page, 'success')).toContainText(`Notification template "${name}" updated.`);
      // Kept as the API set it; the old script left {"url":"$encrypted$","headers":[]}.
      expect(await shownConfig(page)).toEqual(saved);

      await page.locator('#page-content a:has-text("Edit")').click();
      await expect(page.locator('#webhook-signing-secret')).toHaveValue(PLACEHOLDER);
      await expect(page.locator('#webhook-payload')).toHaveValue('event');
      expect(await occurrences(page, signingSecret), 'the signing secret in the edit form').toBe(0);

      // Another URL with the stored signing secret: refused, the secret must be entered again.
      await page.locator('#webhook-url').fill('https://collector.example.net/e2e-signed');
      await submitForm(page);
      await expect(page.locator('.field-nt-config')).toContainText(SIGNING_SECRET_URL_CHANGED);
      expect(await occurrences(page, signingSecret), 'the signing secret after the refusal').toBe(0);

      await page.locator('#webhook-signing-secret').fill(rotatedSecret);
      await submitForm(page);
      await expect(flash(page, 'success')).toContainText(`Notification template "${name}" updated.`);
      expect(await shownConfig(page)).toEqual(saved);
      expect(await occurrences(page, rotatedSecret), 'the new signing secret in the page').toBe(0);

      // Headers that are no JSON object: the form says so and sends nothing.
      await page.locator('#page-content a:has-text("Edit")').click();
      await page.locator('#webhook-headers').fill('{"Authorization": ');
      await page.locator('#nt-submit').click();
      await expect(page.locator('#webhook-headers-error')).toHaveText(HEADERS_FORM);
      await expect(page).toHaveURL(/\/notification-template\/update\?id=\d+$/);
      await page.goto(`/notification-template/view?id=${id}`);
      expect(await shownConfig(page)).toEqual(saved);
    } finally {
      await deleteTemplate(page, id);
    }
  });
});


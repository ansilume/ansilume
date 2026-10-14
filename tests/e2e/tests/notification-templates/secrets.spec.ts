import { test, expect, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { submitForm } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';

/** components/SurveySecrets.php PLACEHOLDER: what the page and the edit form show for a channel secret. */
const PLACEHOLDER = '$encrypted$';

/** components/NotificationConfigSecrets.php FORM_HINT */
const FORM_HINT = `Secrets show as ${PLACEHOLDER}: leave it to keep the stored value, or replace it to change it.`;

/** components/NotificationConfigSecrets.php URL_CHANGED, for the header Authorization after a new url. */
const URL_CHANGED = 'headers.Authorization: url changed; enter the real value.';

/**
 * Mailhog's web port answers every POST with 404 "404 page not found": a test
 * notification to it fails with that answer (SlackChannel, WebhookChannel),
 * which shows the URL the channel used. A URL that is the placeholder could
 * not even be resolved (HTTP 0).
 */
const MAILHOG = 'http://mailhog:8025';

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

      // Baseline: the channel posts to the stored URL, which answers 404.
      await sendTest(page);
      await expect(flash(page, 'danger')).toContainText('Test notification failed: SlackChannel: HTTP 404');

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
      await expect(flash(page, 'danger')).toContainText('Test notification failed: SlackChannel: HTTP 404');
      await expect(flash(page, 'danger')).not.toContainText('HTTP 0');
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
      await expect(flash(page, 'danger')).toContainText('Test notification failed: WebhookChannel: HTTP 404');
    } finally {
      await deleteTemplate(page, id);
    }
  });
});


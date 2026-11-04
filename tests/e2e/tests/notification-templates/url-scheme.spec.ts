import { test, expect, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { submitForm } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';

/** models/NotificationTemplate.php URL_SCHEME_REFUSED, for the key webhook_url. */
const WEBHOOK_URL_REFUSED = 'webhook_url must be an http or https URL.';

/** models/NotificationTemplate.php URL_SCHEME_REFUSED, for the key url of the webhook channel. */
const URL_REFUSED = 'url must be an http or https URL.';

const VIEW_PAGE = /\/notification-template\/view\?id=(\d+)/;

/** Fills the body template, whose textarea a CodeMirror editor hides, and ticks one event. */
async function fillBodyAndEvent(page: Page): Promise<void> {
  await page.locator('textarea[data-template-editor]').evaluate((el) => {
    (el as HTMLTextAreaElement).value = 'E2E {{ event }}';
  });
  await page.locator('.event-checkbox').first().check();
}

/** How many rows of the template list have `name` in their first column. */
async function listedAs(page: Page, name: string): Promise<number> {
  await page.goto('/notification-template/index');
  return page.locator('#page-content table.table tbody tr td:first-child', { hasText: name }).count();
}

/**
 * The URL of a notification template's channel config must be an http or
 * https URL: the channels send to no other (components/OutboundHttpClient.php).
 * Regression: a template stored a URL of any scheme.
 */
test.describe('Notification Template URL schemes', () => {
  test('a Slack webhook URL of another scheme is a form error, and nothing is stored', async ({ page }) => {
    const name = `e2e-nt-url-scheme-${Date.now()}`;
    await page.goto('/notification-template/create');
    await page.locator('#notificationtemplate-name').fill(name);
    await page.locator('#nt-channel').selectOption('slack');
    await page.locator('#slack-webhook-url').fill('ftp://hooks.example.com/e2e');
    await fillBodyAndEvent(page);
    await submitForm(page);

    await expect(page.locator('.field-nt-config')).toContainText(WEBHOOK_URL_REFUSED);
    await expect(flash(page, 'success')).toHaveCount(0);
    expect(await listedAs(page, name), 'the template was not created').toBe(0);
  });

  test('a webhook URL that is a local file is refused, and an https URL is taken', async ({ page }) => {
    const name = `e2e-nt-url-file-${Date.now()}`;
    await page.goto('/notification-template/create');
    await page.locator('#notificationtemplate-name').fill(name);
    await page.locator('#nt-channel').selectOption('webhook');
    await page.locator('#webhook-url').fill('file:///etc/hostname');
    await fillBodyAndEvent(page);
    await submitForm(page);
    await expect(page.locator('.field-nt-config')).toContainText(URL_REFUSED);

    await page.locator('#webhook-url').fill('https://collector.example.net/e2e');
    await submitForm(page);
    await expect(flash(page, 'success')).toContainText(`Notification template "${name}" created.`);
    const id = page.url().match(VIEW_PAGE)?.[1] ?? '';
    expect(id, 'the template page opens').toBeTruthy();
    await forgePost(page, `/notification-template/delete?id=${id}`, {}, /\/notification-template\/index/);
  });
});

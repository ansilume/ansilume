import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { asAdmin, forgePost } from '../../lib/scoping';

/** components/SurveySecrets.php PLACEHOLDER: what the page shows for a channel secret. */
const PLACEHOLDER = '$encrypted$';

/** components/NotificationConfigSecrets.php PAGE_NOTE */
const PAGE_NOTE = `Channel secrets show as ${PLACEHOLDER}.`;

const VIEW_PAGE = /\/notification-template\/view\?id=(\d+)/;

/**
 * Creates a Telegram notification template with `botToken` through a real
 * form post of the open (admin) page; returns its id. A test fixture, not a
 * secret.
 */
async function createTelegramTemplate(admin: Page, name: string, botToken: string): Promise<string> {
  await admin.goto('/notification-template/index');
  await forgePost(admin, '/notification-template/create', {
    'NotificationTemplate[name]': name,
    'NotificationTemplate[channel]': 'telegram',
    'NotificationTemplate[config]': JSON.stringify({ bot_token: botToken, chat_id: '-100200300' }),
    'NotificationTemplate[events]': 'job.failed',
    'NotificationTemplate[subject_template]': 'E2E {{ event }}',
    'NotificationTemplate[body_template]': 'E2E {{ event }}',
  }, VIEW_PAGE);
  const id = admin.url().match(VIEW_PAGE)?.[1];
  expect(id, 'the new template opens').toBeTruthy();
  return id ?? '';
}

async function deleteTemplate(admin: Page, id: string): Promise<void> {
  await admin.goto(`/notification-template/view?id=${id}`);
  await forgePost(admin, `/notification-template/delete?id=${id}`, {}, /\/notification-template\/index/);
}

test.describe('Notification Templates RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/notification-template/create');
    await expectForbidden(page);
  });

  test('viewer can view index', async ({ page }) => {
    await page.goto('/notification-template/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator can create notification templates', async ({ page }) => {
    await page.goto('/notification-template/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  // Regression: every signed-in user holds the default role viewer, and the
  // template page printed the stored channel config: a viewer read the bot
  // token (and every webhook URL, header token and routing key).
  test('viewer sees the placeholder, never the stored bot token, on a template page', async ({ page, browser }, testInfo) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-nt-secret-viewer-${suffix}`;
    const botToken = `424242:AAE-e2e-viewer-must-not-see-${suffix}`;
    const id = await asAdmin(browser, testInfo, (admin) => createTelegramTemplate(admin, name, botToken));
    try {
      await page.goto(`/notification-template/view?id=${id}`);
      await expect(page.locator('#page-content h2').first()).toHaveText(name);

      await expect(page.getByTestId('notification-template-config')).toHaveText(`{"bot_token":"${PLACEHOLDER}","chat_id":"-100200300"}`);
      await expect(page.getByTestId('notification-template-secret-note')).toHaveText(PAGE_NOTE);
      const html = await page.content();
      expect(html.split(botToken).length - 1, 'occurrences of the bot token in the page').toBe(0);
      // A viewer may not edit or test the template either.
      await expect(page.locator('#page-content a:has-text("Edit")')).toHaveCount(0);
    } finally {
      await asAdmin(browser, testInfo, (admin) => deleteTemplate(admin, id));
    }
  });
});

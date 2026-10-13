import { test, expect, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { idWhere, indexRows } from '../../lib/scoping';

/** controllers/NotificationTemplateController.php TEST_SENT */
const TEST_SENT = 'Test notification sent successfully.';

/** controllers/NotificationTemplateController.php TEST_FAILED; `error` is the channel's error. */
function testFailed(error: string): string {
  return `Test notification failed: ${error}`;
}

/**
 * commands/E2eFailingNotificationSeeder.php: a webhook to a port nothing
 * listens on, so every delivery fails at once. services/notification/
 * WebhookChannel.php reports a connection that got no answer as HTTP 0.
 */
const FAILING_NOTIFICATION = 'e2e-failing-notification';
const FAILING_CHANNEL_ERROR = 'WebhookChannel: HTTP 0 — ';

/** Opens the page of the notification template `name` (column 0 of the list: Name). */
async function openNotificationTemplate(page: Page, name: string): Promise<void> {
  const id = idWhere(await indexRows(page, '/notification-template/index'), 0, name);
  await page.goto(`/notification-template/view?id=${id}`);
  await expect(page.locator('#page-content h2').first()).toHaveText(name);
}

/** Clicks Send Test on the open notification template page; the page opens again with the result. */
async function sendTest(page: Page): Promise<void> {
  await Promise.all([
    page.waitForNavigation({ url: /\/notification-template\/view\?id=\d+/ }),
    page.locator('#page-content form[action*="/notification-template/test"] button[type="submit"]').click(),
  ]);
}

/**
 * "Send Test" on a notification template delivers it once through its
 * channel and says whether the channel accepted it.
 */
test.describe('Notification Template Send-Test', () => {
  const MAILHOG_API = 'http://mailhog:8025/api/v2';

  test('send-test email arrives at Mailhog', async ({ page, request }) => {
    // Clear the Mailhog inbox so we see only this test's messages.
    await request.delete(`${MAILHOG_API}/messages`).catch(() => { /* ignore if unsupported */ });

    // The seeded e2e-notification template: email to e2e@example.com.
    await openNotificationTemplate(page, 'e2e-notification');
    await sendTest(page);
    await expect(flash(page, 'success')).toHaveText(TEST_SENT);
    await expect(flash(page, 'danger')).toHaveCount(0);

    // Poll Mailhog for the message with a short loop.
    let total = 0;
    for (let i = 0; i < 10; i++) {
      const res = await request.get(`${MAILHOG_API}/messages`);
      if (res.ok()) {
        const body = await res.json();
        total = typeof body.total === 'number' ? body.total : (body.count ?? 0);
        if (total > 0) break;
      }
      await new Promise((r) => setTimeout(r, 500));
    }
    expect(total).toBeGreaterThan(0);
  });

  // Regression: a failing test notification was reported as sent.
  test('a test notification whose channel fails shows the channel\'s error, and no success', async ({ page }) => {
    await openNotificationTemplate(page, FAILING_NOTIFICATION);
    await sendTest(page);

    await expect(flash(page, 'danger')).toHaveText(testFailed(FAILING_CHANNEL_ERROR));
    await expect(flash(page, 'success')).toHaveCount(0);
    // The flash type is "danger": "error" rendered as the unstyled class alert-error.
    await expect(page.locator('#page-content > .alert-error')).toHaveCount(0);
  });
});

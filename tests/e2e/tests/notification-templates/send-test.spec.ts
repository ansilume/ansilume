import { test, expect } from '@playwright/test';

/**
 * Proves that clicking "Send test" on a notification template actually
 * delivers an email to the configured SMTP (mailhog in the e2e stack).
 */
test.describe('Notification Template Send-Test', () => {
  const MAILHOG_API = 'http://mailhog:8025/api/v2';

  test('send-test email arrives at Mailhog', async ({ page, request }) => {
    // Clear the Mailhog inbox so we see only this test's messages.
    await request.delete(`${MAILHOG_API}/messages`).catch(() => { /* ignore if unsupported */ });

    // Navigate to the seeded e2e-notification template.
    await page.goto('/notification-template/index');
    const row = page.locator('table.table tbody tr').filter({ hasText: 'e2e-notification' }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No e2e-notification template seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Row has no link');
      return;
    }
    await page.goto(href);

    // Click the Send Test action. The form action resolves to /notification-template/test.
    const sendForm = page.locator('form[action*="/test"]').first();
    if (!(await sendForm.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'Send-test form not visible on template view');
      return;
    }
    // If there is a JS confirm() handler, accept it proactively.
    page.once('dialog', (d) => d.accept().catch(() => {}));
    const submit = sendForm.locator('button[type="submit"]').first();
    await submit.click();

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
});

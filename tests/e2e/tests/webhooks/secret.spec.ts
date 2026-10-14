import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';

/** components/SurveySecrets.php PLACEHOLDER: what the edit form's secret field holds for a stored secret. */
const PLACEHOLDER = '$encrypted$';

/** models/Webhook.php SECRET_HINT */
const SECRET_HINT = 'A secret is stored: leave the field as it is to keep it, clear it to send deliveries unsigned, or enter a new one.';

const VIEW_PAGE = /\/webhook\/view\?id=(\d+)/;

/** The text of the Signed row of the open webhook page. */
function signedRow(page: Page) {
  return page.locator('#page-content dt:has-text("Signed") + dd');
}

/** How often `secret` occurs in the HTML of the open page: its text, attributes and form values. */
async function occurrences(page: Page, secret: string): Promise<number> {
  return (await page.content()).split(secret).length - 1;
}

async function openEditForm(page: Page, id: string): Promise<void> {
  await page.goto(`/webhook/update?id=${id}`);
  await expect(page.locator('#webhook-secret')).toBeVisible();
}

/**
 * regression: the edit form's password field carried the stored signing
 * secret as its value, in the HTML of the page. It carries the placeholder
 * now, and saving the form unchanged keeps the stored secret (the stored
 * value itself is checked by tests/integration/controllers/WebhookSecretTest.php).
 */
test.describe('Webhook signing secret', () => {
  test('the edit form shows the placeholder; unchanged it keeps the secret, cleared it removes it', async ({ page }) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-webhook-secret-${suffix}`;
    // A fixture, not a secret: what no page may show.
    const secret = `e2e-hmac-secret-${suffix}`;

    await page.goto('/webhook/create');
    await page.locator('#webhook-name').fill(name);
    await page.locator('#webhook-url').fill(`https://example.com/e2e-webhook-secret-${suffix}`);
    await page.locator('input[type="checkbox"][name*="eventsArray"]').first().check();
    await page.locator('#webhook-secret').fill(secret);
    await submitForm(page);
    await expectFlash(page, 'success', `Webhook "${name}" created.`);
    const id = page.url().match(VIEW_PAGE)?.[1] ?? '';
    expect(id, 'the new webhook opens').toBeTruthy();

    try {
      await expect(signedRow(page)).toContainText('Yes');

      await openEditForm(page, id);
      await expect(page.locator('#webhook-secret')).toHaveValue(PLACEHOLDER);
      await expect(page.locator('#webhook-secret')).toHaveAttribute('type', 'password');
      await expect(page.locator('.field-webhook-secret')).toContainText(SECRET_HINT);
      expect(await occurrences(page, secret), 'the stored secret in the edit form').toBe(0);

      await page.locator('#webhook-name').fill(`${name}-kept`);
      await submitForm(page);
      await expectFlash(page, 'success', `Webhook "${name}-kept" updated.`);
      await expect(signedRow(page)).toContainText('Yes');

      await openEditForm(page, id);
      await expect(page.locator('#webhook-secret')).toHaveValue(PLACEHOLDER);
      await page.locator('#webhook-secret').fill('');
      await submitForm(page);
      await expectFlash(page, 'success', `Webhook "${name}-kept" updated.`);
      await expect(signedRow(page)).toHaveText('No secret configured');

      await openEditForm(page, id);
      await expect(page.locator('#webhook-secret')).toHaveValue('');
      await expect(page.locator('.field-webhook-secret')).not.toContainText(SECRET_HINT);
    } finally {
      await page.goto(`/webhook/view?id=${id}`);
      await forgePost(page, `/webhook/delete?id=${id}`, {}, /\/webhook\/index/);
    }
  });
});

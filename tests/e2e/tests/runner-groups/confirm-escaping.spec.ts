import { test, expect } from '@playwright/test';

// Regression: runner names come from self-registration and were put into the
// confirm() of the "Regen Token" and "Delete" forms with addslashes(). A double
// quote ended the onsubmit attribute, so a crafted name injected attributes
// into the page of every admin who opened the runner group (stored XSS).
const QUOTE_RUNNER = 'e2e-quote-runner" data-e2e-injected="1';

test.describe('Runner group confirm dialogs', () => {
  test('regression: runner names with quotes cannot inject attributes', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    const row = page.locator('table.table tbody tr', { hasText: 'e2e-quote-runner' });
    await expect(row).toHaveCount(1);
    await expect(page.locator('[data-e2e-injected]')).toHaveCount(0);

    const messages: string[] = [];
    page.on('dialog', async (dialog) => {
      messages.push(dialog.message());
      await dialog.dismiss();
    });
    await row.locator('button:has-text("Regen Token")').click();
    await row.locator('button:has-text("Delete")').click();

    await expect.poll(() => messages.length).toBe(2);
    expect(messages[0]).toBe(`Regenerate token for "${QUOTE_RUNNER}"? The old token will stop working immediately.`);
    expect(messages[1]).toBe(`Delete runner "${QUOTE_RUNNER}"?`);
    // Both dialogs were dismissed, so nothing was submitted.
    await expect(row).toHaveCount(1);
  });
});

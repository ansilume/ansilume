import { test, expect } from '@playwright/test';

// Pins the extra-vars editor progressive enhancement:
//   - Visiting /job-template/create loads the CodeMirror editor
//   - A JSON/YAML format toggle is visible
//   - Switching to YAML converts the buffer without losing data
//   - The hidden textarea stays JSON (server contract unchanged)

test.describe('Extra Vars Editor', () => {
  test('CodeMirror editor and format toggle are present on create', async ({ page }) => {
    await page.goto('/job-template/create');
    // The CM editor root
    await expect(page.locator('.CodeMirror').first()).toBeVisible({ timeout: 5_000 });
    // Format toggle buttons
    await expect(page.getByRole('button', { name: 'JSON' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'YAML' })).toBeVisible();
  });

  test('typing JSON keeps the hidden textarea in sync', async ({ page }) => {
    await page.goto('/job-template/create');
    await page.locator('.CodeMirror').first().click();
    await page.keyboard.type('{"env": "production"}');
    // Give CM's change handler a tick to run
    await page.waitForTimeout(150);
    const textareaValue = await page
      .locator('textarea[data-extra-vars-editor]')
      .first()
      .inputValue();
    const parsed = JSON.parse(textareaValue);
    expect(parsed).toEqual({ env: 'production' });
  });

  test('toggling to YAML converts the buffer and hidden textarea stays JSON', async ({ page }) => {
    await page.goto('/job-template/create');
    // Seed JSON in the editor first.
    await page.locator('.CodeMirror').first().click();
    await page.keyboard.type('{"env":"staging","forks":10}');
    await page.waitForTimeout(150);

    await page.getByRole('button', { name: 'YAML' }).click();
    await page.waitForTimeout(150);

    // Visible editor content must be YAML-shaped (env: staging on its own line)
    const cmText = await page.locator('.CodeMirror-code').first().innerText();
    expect(cmText).toMatch(/env:\s*staging/);
    expect(cmText).toMatch(/forks:\s*10/);

    // Hidden textarea must still carry JSON — server contract is JSON.
    const textareaValue = await page
      .locator('textarea[data-extra-vars-editor]')
      .first()
      .inputValue();
    const parsed = JSON.parse(textareaValue);
    expect(parsed).toEqual({ env: 'staging', forks: 10 });
  });

  test('invalid JSON shows an error marker and does NOT overwrite the last-good hidden value', async ({ page }) => {
    await page.goto('/job-template/create');
    await page.locator('.CodeMirror').first().click();
    await page.keyboard.type('{"broken": ');
    await page.waitForTimeout(150);

    // Status line signals the parse failure.
    await expect(page.locator('.extra-vars-editor__status')).toContainText(/JSON/);
    await expect(page.locator('.extra-vars-editor__status.text-danger')).toBeVisible();

    // Textarea must NOT contain the broken value — the client keeps the
    // last-valid JSON so the form POST never ships garbage.
    const textareaValue = await page
      .locator('textarea[data-extra-vars-editor]')
      .first()
      .inputValue();
    expect(textareaValue).not.toContain('{"broken":');
  });
});

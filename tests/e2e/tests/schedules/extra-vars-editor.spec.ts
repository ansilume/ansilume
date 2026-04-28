import { test, expect } from '@playwright/test';

// The schedule form's extra_vars textarea is enhanced with the same
// CodeMirror editor that's been on /job-template/create since v2.3.3.
// Pin it so a future view edit can't silently drop the wiring.

test.describe('Schedule extra_vars editor', () => {
  test('CodeMirror editor and JSON/YAML toggle render on /schedule/create', async ({ page }) => {
    await page.goto('/schedule/create');

    // The wrapper class the dark-mode CSS targets — same hook the
    // job-template + launch pages use.
    await expect(page.locator('.extra-vars-editor').first()).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('.extra-vars-editor .CodeMirror').first()).toBeVisible();

    // The original textarea is still in the DOM (Yii's ActiveForm wiring
    // depends on it) but hidden behind the editor.
    const textarea = page.locator('textarea[data-extra-vars-editor]');
    await expect(textarea).toHaveCount(1);
    await expect(textarea).toBeHidden();

    // JSON/YAML toggle is the same control the job-template editor exposes.
    await expect(page.getByRole('button', { name: 'JSON' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'YAML' })).toBeVisible();
  });
});

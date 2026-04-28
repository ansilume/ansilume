import { test, expect } from '@playwright/test';

// Pins the YAML editor progressive enhancement on the static-inventory
// edit form. Mirrors the look of the job-template extra-vars editor but
// without the JSON/YAML toggle — inventories store raw YAML, so the
// backing textarea must always carry exactly what the editor shows.

test.describe('Inventory YAML editor', () => {
  test('CodeMirror replaces the static content textarea on edit', async ({ page }) => {
    await page.goto('/inventory/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-inventory' }).first();
    await expect(row).toBeVisible({ timeout: 5_000 });
    await row.locator('a').filter({ hasText: 'e2e-inventory' }).first().click();
    await page.locator('a:has-text("Edit"), a:has-text("Update")').first().click();
    await page.waitForLoadState('domcontentloaded');

    // The original textarea is still in the DOM (so Yii's ActiveForm wiring
    // keeps working) but hidden — the yaml-editor JS replaces the visible
    // surface with CodeMirror.
    const textarea = page.locator('textarea[data-yaml-editor]');
    await expect(textarea).toHaveCount(1);
    await expect(textarea).toBeHidden();

    // The wrapper class is the hook the dark-mode CSS targets.
    await expect(page.locator('.yaml-editor').first()).toBeVisible();
    await expect(page.locator('.yaml-editor .CodeMirror').first()).toBeVisible();

    // No JSON/YAML toggle on this editor — that's the only visual
    // difference vs. the extra-vars editor.
    await expect(page.locator('.yaml-editor [data-ev-format]')).toHaveCount(0);
  });

  test('typing YAML stays YAML in the backing textarea (no JSON conversion)', async ({ page }) => {
    await page.goto('/inventory/create');
    // Static is the default inventory type, so the content field is visible.
    const cm = page.locator('.yaml-editor .CodeMirror').first();
    await expect(cm).toBeVisible({ timeout: 5_000 });
    await cm.click();
    await page.keyboard.type('all:\n  hosts:\n    db1.example.com:');

    await page.waitForTimeout(150);

    // The hidden textarea must carry the raw YAML the user typed — not
    // a JSON-encoded version. (The extra-vars editor JSON-encodes; this
    // one must NOT.)
    const value = await page.locator('textarea[data-yaml-editor]').inputValue();
    expect(value).toContain('hosts:');
    expect(value).toContain('db1.example.com:');
    expect(value.trimStart()).not.toMatch(/^[{\[]/, 'Backing textarea must NOT be JSON.');
  });

  test('invalid YAML shows a parse error in the status line', async ({ page }) => {
    await page.goto('/inventory/create');
    const cm = page.locator('.yaml-editor .CodeMirror').first();
    await expect(cm).toBeVisible({ timeout: 5_000 });
    await cm.click();
    // Tabs are illegal indentation in YAML — js-yaml flags this.
    await page.keyboard.type('all:\n\thosts:');
    await page.waitForTimeout(200);

    await expect(page.locator('.yaml-editor__status.text-danger')).toBeVisible();
    await expect(page.locator('.yaml-editor__status')).toContainText(/YAML/);
  });
});

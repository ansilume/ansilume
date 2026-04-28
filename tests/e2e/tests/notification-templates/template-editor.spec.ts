import { test, expect } from '@playwright/test';

// Notification templates carry Twig/Jinja-style content
// ("[Ansilume] {{ event }} — {{ template.name }}"). The body_template
// textarea is enhanced with template-editor.js which mounts CodeMirror
// in a small inline mode that highlights {{ var }}, {% tag %},
// {# comment #}. Pin both the wrapper presence and the highlighted
// span class so a future mode rename surfaces here, not in production.

test.describe('Notification template editor', () => {
  test('CodeMirror editor renders for body_template on /notification-template/create', async ({ page }) => {
    await page.goto('/notification-template/create');

    await expect(page.locator('.template-editor').first()).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('.template-editor .CodeMirror').first()).toBeVisible();

    // Backing textarea hidden but still in DOM for the form POST.
    const textarea = page.locator('textarea[data-template-editor]');
    await expect(textarea).toHaveCount(1);
    await expect(textarea).toBeHidden();
  });

  test('Jinja-style {{ var }} is rendered as a highlighted token', async ({ page }) => {
    await page.goto('/notification-template/create');
    await expect(page.locator('.template-editor .CodeMirror').first()).toBeVisible({ timeout: 5_000 });

    // The default body_template the form pre-fills contains {{ event }} etc.
    // CodeMirror tokenises that into <span class="cm-property">{{ event }}</span>.
    const propertyTokens = page.locator('.template-editor .cm-property');
    await expect(propertyTokens.first()).toBeVisible();
    await expect(propertyTokens.first()).toContainText('{{');
  });
});

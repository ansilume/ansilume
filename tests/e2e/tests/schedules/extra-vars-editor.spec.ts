import { test, expect, Page } from '@playwright/test';
import { createHash } from 'node:crypto';
import { indexRows } from '../../lib/scoping';
import { SECRET_TEMPLATE } from '../../lib/survey-secrets';

// The schedule form's extra_vars textarea is enhanced with the same
// CodeMirror editor that's been on /job-template/create since v2.3.3.
// Pin it so a future view edit can't silently drop the wiring.

/** The refusal of models/Schedule.php::validateJson(), worded like the launch's (SurveySecrets::unencodable()). */
const TOO_LARGE = 'The extra vars cannot be stored: they contain a number too large for JSON.';

/** The first 12 hex characters of the SHA-256 of what `url` serves. */
async function contentVersion(page: Page, url: string): Promise<string> {
  const body = await (await page.request.get(url)).body();
  return createHash('sha256').update(body).digest('hex').slice(0, 12);
}

/** Selects the job template named `name` in the schedule form; its options read "<name> (<id>)". */
async function selectTemplate(page: Page, name: string): Promise<void> {
  const template = page.locator('#schedule-job_template_id');
  const value = await template.locator('option').evaluateAll(
    (options, wanted) => options.find((option) => (option.textContent ?? '').trim().startsWith(`${wanted} (`))?.getAttribute('value') ?? '',
    name,
  );
  expect(value, `the schedule form offers ${name}`).not.toBe('');
  await template.selectOption(value);
}

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

  // Regression: browsers kept an older editor for days after an update:
  // nginx sends /js/ without Cache-Control, and the URL never changed.
  test('the schedule form loads the editor with the version of its content', async ({ page }) => {
    await page.goto('/schedule/create');
    const script = await page.locator('script[src*="/js/extra-vars-editor.js"]').getAttribute('src') ?? '';
    const stylesheet = await page.locator('link[href*="/css/extra-vars-editor.css"]').getAttribute('href') ?? '';

    expect(script).toMatch(/^\/js\/extra-vars-editor\.js\?v=[0-9a-f]{12}$/);
    expect(stylesheet).toMatch(/^\/css\/extra-vars-editor\.css\?v=[0-9a-f]{12}$/);
    expect(script.split('?v=')[1]).toBe(await contentVersion(page, script));
    expect(stylesheet.split('?v=')[1]).toBe(await contentVersion(page, stylesheet));
  });

  // Regression: JSON allows 1e999. The editor posted it as null, so the
  // schedule stored null; posted as typed, the schedule stored a number PHP
  // reads as infinity, and every run failed to launch.
  test('a number too large for JSON is refused at save with the reason, and no schedule is created', async ({ page }) => {
    const name = `e2e-inf-schedule-${Date.now()}`;
    await page.goto('/schedule/create');
    await page.locator('#schedule-name').fill(name);
    await selectTemplate(page, SECRET_TEMPLATE);
    await page.locator('#cron-input').fill('0 0 1 1 *');
    await page.locator('#schedule-enabled').uncheck();
    const typed = '{"retries": 1e999}';
    await page.locator('.extra-vars-editor .CodeMirror').click();
    await page.keyboard.press('Control+A');
    await page.keyboard.insertText(typed);
    await expect(page.locator('#schedule-extra_vars'), 'posted as typed').toHaveValue(typed);

    await Promise.all([
      page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/schedule/create')),
      page.locator('#schedule-form button[type="submit"]').click(),
    ]);

    await expect(page.locator('.field-schedule-extra_vars .help-block')).toHaveText(TOO_LARGE);
    await expect(page).toHaveURL(/\/schedule\/create/);
    const names = (await indexRows(page, '/schedule/index')).map((row) => row.cells[1]);
    expect(names, 'no schedule was created').not.toContain(name);
  });
});

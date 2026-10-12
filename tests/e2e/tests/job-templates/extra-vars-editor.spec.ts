import { test, expect, Locator, Page, Request } from '@playwright/test';
import { createHash } from 'node:crypto';
import { expectFlash } from '../../lib/helpers';
import { PLACEHOLDER, expectNotInPage } from '../../lib/survey-secrets';
import { openJobTemplate } from '../../lib/triggers';

/**
 * The Extra Vars editor (web/js/extra-vars-editor.js): CodeMirror with a
 * JSON/YAML switch over the form's extra_vars textarea, on the job template
 * form and the launch page.
 *
 * - On load the field keeps the stored text: an edit of other fields posts
 *   it unchanged. In JSON mode an edit posts the text as typed.
 * - YAML mode and the switch refuse a number JavaScript cannot carry
 *   exactly (an integer beyond ±9007199254740991, or one too large for JSON).
 * - While the text does not parse, or YAML mode refuses it, the form is not
 *   submitted.
 *
 * Fixtures (commands/E2eExtraVarsEditorSeeder.php): templates whose Extra
 * Vars are stored as text. The tests leave them as they are, so a run
 * without a new e2e/seed sees the same state.
 */
const BIGINT_TEMPLATE = 'e2e-extra-vars-bigint-template';
const BIGINT_EXTRA_VARS = '{"build_id": 9007199254740993}';
const BIGINT_SECRET_TEMPLATE = 'e2e-extra-vars-bigint-secret-template';
const BIGINT_SECRET_VALUE = 'e2e-legacy-extra-var-Zt41';
const BIGINT_SECRET_EXTRA_VARS = `{"build_id": 9007199254740993, "db_password": "${BIGINT_SECRET_VALUE}"}`;
const REFUSAL_TEMPLATE = 'e2e-extra-vars-refusal-template';
const REFUSAL_EXTRA_VARS = '{"timeout_s": 30}';
/** The refusal of models/JobTemplate.php::validateJson(), worded like the launch's (SurveySecrets::unencodable()). */
const TOO_LARGE = 'The extra vars cannot be stored: they contain a number too large for JSON.';
/** The editor's refusals. */
const NOT_CONVERTED_TO_YAML = 'Not converted to YAML: the JSON holds a number the editor cannot convert exactly';
const INEXACT_YAML = 'The YAML holds a number the editor cannot convert exactly';

const TEMPLATE_EDITOR = '.field-jobtemplate-extra_vars .CodeMirror';
const TEMPLATE_FIELD = '#jobtemplate-extra_vars';
const LAUNCH_EDITOR = '#advanced-overrides .CodeMirror';
const LAUNCH_FIELD = 'textarea[name="overrides[extra_vars]"]';

/** The text the editor shows. */
async function editorText(editor: Locator): Promise<string> {
  await expect(editor).toBeVisible();
  return editor.evaluate((element) => (element as unknown as { CodeMirror: { getValue(): string } }).CodeMirror.getValue());
}

/** Replaces the editor's text as a paste would (no auto-closed brackets). */
async function replaceText(page: Page, editor: Locator, text: string): Promise<void> {
  await editor.click();
  await page.keyboard.press('Control+A');
  await page.keyboard.insertText(text);
}

/**
 * The Extra Vars the template has stored: the edit form's textarea as the
 * server renders it, read without any script.
 */
async function storedExtraVars(page: Page, id: string): Promise<string> {
  const html = await (await page.request.get(`/job-template/update?id=${id}`)).text();
  return page.evaluate((source) => {
    const field = new DOMParser().parseFromString(source, 'text/html').querySelector('#jobtemplate-extra_vars');
    return field instanceof HTMLTextAreaElement ? field.value : '<no field>';
  }, html);
}

/** Saves the open template form; the request it posted. */
async function saveTemplateForm(page: Page, id: string): Promise<Request> {
  const [request] = await Promise.all([
    page.waitForRequest((r) => r.method() === 'POST' && r.url().includes(`/job-template/update?id=${id}`)),
    page.locator('#jt-form button[type="submit"]', { hasText: 'Save Changes' }).click(),
  ]);
  return request;
}

/** A form field of a posted request. */
function postedField(request: Request, name: string): string | null {
  return new URLSearchParams(request.postData() ?? '').get(name);
}

/** Records every POST the page sends, to show that a blocked submit sent none. */
function recordPosts(page: Page): string[] {
  const posts: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST') {
      posts.push(request.url());
    }
  });
  return posts;
}

/** The editor is marked as blocked, says why, and has the focus. */
async function expectBlocked(page: Page, reason: string | RegExp): Promise<void> {
  const shell = page.locator('.extra-vars-editor').first();
  await expect(shell).toHaveClass(/extra-vars-editor--blocked/);
  await expect(shell.locator('.extra-vars-editor__status')).toHaveClass(/text-danger/);
  await expect(shell.locator('.extra-vars-editor__status')).toContainText(reason);
  await expect.poll(() => page.evaluate(() => Boolean(document.activeElement?.closest('.extra-vars-editor'))), 'the editor has the focus')
    .toBe(true);
}

/**
 * Lets the template form's POSTs leave the page but not reach the server:
 * for tests that only check what a submit sends, so the fixture stays as
 * the seed left it. A 204 answer keeps the browser on the form.
 */
async function holdTemplatePosts(page: Page): Promise<void> {
  await page.route(/\/job-template\/update\?id=\d+$/, (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 204 }) : route.continue()));
}

/** The first 12 hex characters of the SHA-256 of what `url` serves. */
async function contentVersion(page: Page, url: string): Promise<string> {
  const body = await (await page.request.get(url)).body();
  return createHash('sha256').update(body).digest('hex').slice(0, 12);
}

test.describe('Extra Vars Editor', () => {
  test('CodeMirror editor and format toggle are present on create', async ({ page }) => {
    await page.goto('/job-template/create');
    await expect(page.locator('.CodeMirror').first()).toBeVisible({ timeout: 5_000 });
    await expect(page.getByRole('button', { name: 'JSON' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'YAML' })).toBeVisible();
  });

  // Regression: browsers kept an older editor for days after an update:
  // nginx sends /js/ without Cache-Control, and the URL never changed.
  test('the template form and the launch page load the editor with the version of its content', async ({ page }) => {
    const id = await openJobTemplate(page, BIGINT_TEMPLATE);
    for (const url of ['/job-template/create', `/job-template/launch?id=${id}`]) {
      await page.goto(url);
      const script = await page.locator('script[src*="/js/extra-vars-editor.js"]').getAttribute('src') ?? '';
      const stylesheet = await page.locator('link[href*="/css/extra-vars-editor.css"]').getAttribute('href') ?? '';
      expect(script, url).toMatch(/^\/js\/extra-vars-editor\.js\?v=[0-9a-f]{12}$/);
      expect(stylesheet, url).toMatch(/^\/css\/extra-vars-editor\.css\?v=[0-9a-f]{12}$/);
      expect(script.split('?v=')[1], 'the version of the script that is served').toBe(await contentVersion(page, script));
      expect(stylesheet.split('?v=')[1], 'the version of the stylesheet that is served').toBe(await contentVersion(page, stylesheet));
    }
  });

  test('typing JSON writes it to the field as typed', async ({ page }) => {
    await page.goto('/job-template/create');
    const typed = '{"env": "production",  "ratio": 1.0, "build_id": 9007199254740993}';
    await replaceText(page, page.locator(TEMPLATE_EDITOR), typed);

    await expect(page.locator(TEMPLATE_FIELD)).toHaveValue(typed);
    await expect(page.locator('.extra-vars-editor__status')).toHaveText('✓ valid JSON');
  });

  test('toggling to YAML converts the buffer and the field stays JSON', async ({ page }) => {
    await page.goto('/job-template/create');
    await replaceText(page, page.locator(TEMPLATE_EDITOR), '{"env":"staging","forks":10}');

    await page.getByRole('button', { name: 'YAML' }).click();

    const yaml = await editorText(page.locator(TEMPLATE_EDITOR));
    expect(yaml).toMatch(/env:\s*staging/);
    expect(yaml).toMatch(/forks:\s*10/);
    expect(JSON.parse(await page.locator(TEMPLATE_FIELD).inputValue())).toEqual({ env: 'staging', forks: 10 });
    await expect(page.locator('.extra-vars-editor__status')).toHaveText('✓ valid YAML');
  });

  // Regression: on load the editor wrote JSON.stringify(JSON.parse(stored))
  // into the field, which rounds 9007199254740993 to 9007199254740992: every
  // save of the form changed the stored number, silently.
  test('a description-only edit posts Extra Vars with an integer above 2^53 unchanged', async ({ page }) => {
    const id = await openJobTemplate(page, BIGINT_TEMPLATE);
    expect(await storedExtraVars(page, id), 'the stored Extra Vars').toBe(BIGINT_EXTRA_VARS);

    await page.goto(`/job-template/update?id=${id}`);
    expect(await editorText(page.locator(TEMPLATE_EDITOR))).toBe(BIGINT_EXTRA_VARS);
    await expect(page.locator(TEMPLATE_FIELD), 'the field keeps the stored text').toHaveValue(BIGINT_EXTRA_VARS);
    await page.locator('#jobtemplate-description').fill(`E2E big integer, edited ${Date.now()}`);
    const posted = await saveTemplateForm(page, id);

    await expect(page).toHaveURL(new RegExp(`/job-template/view\\?id=${id}$`), { timeout: 15_000 });
    await expectFlash(page, 'success', `Template "${BIGINT_TEMPLATE}" updated.`);
    expect(postedField(posted, 'JobTemplate[extra_vars]'), 'posted as stored').toBe(BIGINT_EXTRA_VARS);
    expect(await storedExtraVars(page, id), 'stored unchanged').toBe(BIGINT_EXTRA_VARS);
  });

  // Regression: the rounded integer counted as a change of the Extra Vars,
  // and a template whose Extra Vars also hold a password field's key (saved
  // before such keys were refused) could not be edited at all: a new
  // description was refused with "Remove "db_password" from Extra Vars".
  test('a description-only edit of a legacy template with a password key and such an integer saves', async ({ page }) => {
    const id = await openJobTemplate(page, BIGINT_SECRET_TEMPLATE);
    expect(await storedExtraVars(page, id), 'the stored Extra Vars').toBe(BIGINT_SECRET_EXTRA_VARS);

    await page.goto(`/job-template/update?id=${id}`);
    await expect(page.locator(TEMPLATE_FIELD)).toHaveValue(BIGINT_SECRET_EXTRA_VARS);
    const description = `E2E legacy key and big integer, edited ${Date.now()}`;
    await page.locator('#jobtemplate-description').fill(description);
    const posted = await saveTemplateForm(page, id);

    await expect(page, 'saved, not sent back to the form').toHaveURL(new RegExp(`/job-template/view\\?id=${id}$`), { timeout: 15_000 });
    await expectFlash(page, 'success', `Template "${BIGINT_SECRET_TEMPLATE}" updated.`);
    expect(postedField(posted, 'JobTemplate[extra_vars]')).toBe(BIGINT_SECRET_EXTRA_VARS);
    await expectNotInPage(page, BIGINT_SECRET_VALUE);
    const shown = (await page.getByTestId('template-extra-vars').textContent()) ?? '';
    expect(shown, 'the integer as stored').toContain('9007199254740993');
    expect(shown).toContain(PLACEHOLDER);
    expect(await storedExtraVars(page, id)).toBe(BIGINT_SECRET_EXTRA_VARS);
  });

  // Regression: JSON allows 1e999, which the editor posted as null (so a
  // save stored null) and which the server then stored as infinity when
  // posted as typed: every launch of the template was refused.
  test('a number too large for JSON is refused at save with the reason, and nothing is stored', async ({ page }) => {
    const id = await openJobTemplate(page, REFUSAL_TEMPLATE);
    expect(await storedExtraVars(page, id), 'the stored Extra Vars').toBe(REFUSAL_EXTRA_VARS);
    await page.goto(`/job-template/update?id=${id}`);
    const typed = '{"timeout_s": 1e999}';
    await replaceText(page, page.locator(TEMPLATE_EDITOR), typed);
    await expect(page.locator(TEMPLATE_FIELD), 'posted as typed').toHaveValue(typed);

    const posted = await saveTemplateForm(page, id);

    expect(postedField(posted, 'JobTemplate[extra_vars]')).toBe(typed);
    await expect(page.locator('.field-jobtemplate-extra_vars .help-block')).toHaveText(TOO_LARGE);
    await expect(page).toHaveURL(new RegExp(`/job-template/update\\?id=${id}$`));
    expect(await editorText(page.locator(TEMPLATE_EDITOR)), 'the form shows what was typed').toBe(typed);
    expect(await storedExtraVars(page, id), 'nothing is stored').toBe(REFUSAL_EXTRA_VARS);
  });

  // Regression: the switch went through JSON.parse, so 9007199254740993
  // became 9007199254740992 in the YAML, and back in the field.
  test('switching JSON with an integer above 2^53 to YAML is refused, and the text stays', async ({ page }) => {
    const id = await openJobTemplate(page, BIGINT_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    const editor = page.locator(TEMPLATE_EDITOR);
    expect(await editorText(editor)).toBe(BIGINT_EXTRA_VARS);

    await page.getByRole('button', { name: 'YAML' }).click();

    await expect(page.locator('.extra-vars-editor__status')).toContainText(NOT_CONVERTED_TO_YAML);
    expect(await editorText(editor), 'the buffer is not converted').toBe(BIGINT_EXTRA_VARS);
    await expect(page.getByRole('button', { name: 'JSON' }), 'the format stays JSON').toHaveClass(/active/);
    await expect(page.getByRole('button', { name: 'YAML' })).not.toHaveClass(/active/);
    await expect(page.locator(TEMPLATE_FIELD)).toHaveValue(BIGINT_EXTRA_VARS);
  });

  // Regression: in YAML mode the field got JSON.stringify(jsyaml.load(text)),
  // with the integer rounded, and the form posted it.
  test('YAML with an integer above 2^53 is refused: not converted, not posted', async ({ page }) => {
    const id = await openJobTemplate(page, REFUSAL_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    const posts = recordPosts(page);
    await page.getByRole('button', { name: 'YAML' }).click();
    const editor = page.locator(TEMPLATE_EDITOR);
    expect(await editorText(editor)).toMatch(/^timeout_s: 30\s*$/);
    await replaceText(page, editor, 'build_id: 9007199254740993');

    await expect(page.locator('.extra-vars-editor__status')).toContainText(INEXACT_YAML);
    await expect(page.locator(TEMPLATE_FIELD), 'never the rounded number').not.toHaveValue(/9007199254740992/);
    await page.getByRole('button', { name: 'JSON' }).click();
    expect(await editorText(editor), 'not converted to JSON either').toBe('build_id: 9007199254740993');
    await expect(page.getByRole('button', { name: 'YAML' })).toHaveClass(/active/);

    await page.locator('#jt-form button[type="submit"]').click();
    await expectBlocked(page, INEXACT_YAML);
    expect(posts, 'nothing was posted').toEqual([]);
  });

  // Regression: invalid JSON left the field at the last valid JSON, and the
  // form saved that instead of what the editor showed.
  test('invalid JSON blocks the submit, shows the status and focuses the editor', async ({ page }) => {
    const id = await openJobTemplate(page, REFUSAL_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    await holdTemplatePosts(page);
    const posts = recordPosts(page);
    const editor = page.locator(TEMPLATE_EDITOR);
    await replaceText(page, editor, '{"timeout_s": ');
    await page.locator('#jobtemplate-description').click();

    await page.locator('#jt-form button[type="submit"]').click();

    await expectBlocked(page, /JSON: /);
    await expect(page).toHaveURL(new RegExp(`/job-template/update\\?id=${id}$`));
    expect(posts, 'nothing was posted').toEqual([]);
    await expect(page.locator(TEMPLATE_FIELD), 'never the last valid JSON').toHaveValue('{"timeout_s": ');

    // Valid again: the mark goes, and the form submits what the editor shows.
    await replaceText(page, editor, '{"timeout_s": 45}');
    await expect(page.locator('.extra-vars-editor')).not.toHaveClass(/extra-vars-editor--blocked/);
    const posted = await saveTemplateForm(page, id);
    expect(postedField(posted, 'JobTemplate[extra_vars]')).toBe('{"timeout_s": 45}');
  });

  // Text that does not parse is not converted: only the mode changes, so
  // YAML pasted in JSON mode becomes valid by switching to YAML.
  test('YAML typed in JSON mode is taken as YAML when switching', async ({ page }) => {
    await page.goto('/job-template/create');
    const editor = page.locator(TEMPLATE_EDITOR);
    await replaceText(page, editor, 'env: staging');
    await expect(page.locator('.extra-vars-editor__status')).toContainText('JSON: ');

    await page.getByRole('button', { name: 'YAML' }).click();

    expect(await editorText(editor)).toBe('env: staging');
    await expect(page.locator('.extra-vars-editor__status')).toHaveText('✓ valid YAML');
    await expect(page.locator(TEMPLATE_FIELD)).toHaveValue('{"env":"staging"}');
  });

  // Without CodeMirror or js-yaml the plain textarea stays the field.
  test('without the editor libraries the plain textarea stays and posts what is typed', async ({ page }) => {
    await page.route(/\/js\/vendor\/codemirror\/codemirror\.js$/, (route) => route.abort());
    const id = await openJobTemplate(page, REFUSAL_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    await holdTemplatePosts(page);
    const field = page.locator(TEMPLATE_FIELD);
    await expect(field).toBeVisible();
    await expect(page.locator('.extra-vars-editor')).toHaveCount(0);

    await field.fill('{"timeout_s": 60}');
    const posted = await saveTemplateForm(page, id);

    expect(postedField(posted, 'JobTemplate[extra_vars]')).toBe('{"timeout_s": 60}');
  });

  test('an empty editor posts an empty value', async ({ page }) => {
    const id = await openJobTemplate(page, REFUSAL_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    await holdTemplatePosts(page);
    await replaceText(page, page.locator(TEMPLATE_EDITOR), '   ');

    await expect(page.locator(TEMPLATE_FIELD)).toHaveValue('');
    await expect(page.locator('.extra-vars-editor__status')).toHaveText('');
    const posted = await saveTemplateForm(page, id);
    expect(postedField(posted, 'JobTemplate[extra_vars]')).toBe('');
  });
});

test.describe('Extra Vars Editor on the launch page', () => {
  // Regression: the launch page's Advanced Overrides hold the template's
  // Extra Vars, and the editor posted them rewritten: the job ran with
  // 9007199254740992 instead of the template's 9007199254740993.
  test('a launch without edits runs the job with the template\'s integer above 2^53', async ({ page }) => {
    const id = await openJobTemplate(page, BIGINT_TEMPLATE);
    await page.goto(`/job-template/launch?id=${id}`);
    await expect(page.locator(LAUNCH_FIELD)).toHaveValue(BIGINT_EXTRA_VARS);

    await Promise.all([
      page.waitForURL(/\/job\/view\?id=\d+/),
      page.locator('#launch-form button[type="submit"]').click(),
    ]);

    const card = page.locator('.card', { has: page.locator('.card-header', { hasText: 'Extra Vars (launch-time)' }) });
    await expect(card.locator('pre')).toHaveText('{"build_id":9007199254740993}');
  });

  // Regression: invalid JSON in Advanced Overrides left the field at the
  // last valid JSON, so the launch ran with other extra vars than shown.
  test('invalid JSON in Advanced Overrides blocks the launch', async ({ page }) => {
    const id = await openJobTemplate(page, BIGINT_TEMPLATE);
    await page.goto(`/job-template/launch?id=${id}`);
    const posts = recordPosts(page);
    await replaceText(page, page.locator(LAUNCH_EDITOR), '{"build_id": ');

    await page.locator('#launch-form button[type="submit"]').click();

    await expectBlocked(page, /JSON: /);
    await expect(page).toHaveURL(new RegExp(`/job-template/launch\\?id=${id}$`));
    expect(posts, 'no launch was posted').toEqual([]);
  });

  // A template with a survey shows Advanced Overrides collapsed: a blocked
  // launch opens them again, so the reason is in view.
  test('a blocked launch opens the collapsed Advanced Overrides and focuses the editor', async ({ page }) => {
    const id = await openJobTemplate(page, 'e2e-survey-template');
    await page.goto(`/job-template/launch?id=${id}`);
    const posts = recordPosts(page);
    const card = page.locator('#advanced-overrides');
    await page.locator('[data-bs-target="#advanced-overrides"]').click();
    await expect(page.locator('#advanced-overrides.collapse.show')).toBeVisible();
    await replaceText(page, page.locator(LAUNCH_EDITOR), '{"app_env": ');
    await page.locator('[data-bs-target="#advanced-overrides"]').click();
    await expect(card).toBeHidden();

    await page.locator('#launch-form button[type="submit"]').click();

    await expect(card).toBeVisible();
    await expectBlocked(page, /JSON: /);
    expect(posts, 'no launch was posted').toEqual([]);
  });
});

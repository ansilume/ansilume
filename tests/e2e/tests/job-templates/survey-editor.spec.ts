import { test, expect, Locator, Page, Request } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { PLACEHOLDER, expectNotInPage } from '../../lib/survey-secrets';
import { openJobTemplate } from '../../lib/triggers';

/**
 * The survey editor of the job template form (views/job-template/_survey_editor.php).
 * Fixtures: commands/E2eSurveyEditorSeeder.php.
 *
 * - e2e-survey-xss-template: survey text that ends a script element or opens
 *   an HTML comment in it. The editor puts the survey into an inline script.
 * - e2e-survey-legacy-template: saved before the survey rules, with the field
 *   name db-host (no variable name) and a password field with a default. No
 *   test saves it, so it keeps that default for the whole run.
 * - e2e-survey-legacy-edit-template: the same survey, for the test that saves
 *   an edit (saving drops the password default).
 * - e2e-survey-extra-vars-template: Extra Vars with a value for its password
 *   field, saved before such keys were refused.
 * - e2e-survey-rewritten-vars-template: such Extra Vars too, with a float
 *   written 1.0, which the form's Extra Vars editor used to post rewritten
 *   as 1. The editor now posts the stored text, so the edit test leaves the
 *   text as the seed stored it and passes again without a new e2e/seed.
 */
const XSS_TEMPLATE = 'e2e-survey-xss-template';
const LEGACY_TEMPLATE = 'e2e-survey-legacy-template';
const LEGACY_EDIT_TEMPLATE = 'e2e-survey-legacy-edit-template';
const XSS_LABEL = '</script><script>window.__e2eSurveyXss = 1</script>';
const XSS_HINT = '<!--<script>';
const XSS_DEFAULT = '</script><script>window.__e2eSurveyXss = 2</script>';
const LEGACY_DEFAULT = 'e2e-legacy-default-Jv38';
const EXTRA_VARS_TEMPLATE = 'e2e-survey-extra-vars-template';
const LEGACY_EXTRA_VAR = 'e2e-legacy-extra-var-Pq90';
const REWRITTEN_VARS_TEMPLATE = 'e2e-survey-rewritten-vars-template';
/** E2eSurveyEditorSeeder::REWRITTEN_EXTRA_VAR and ::REWRITTEN_EXTRA_VARS, the stored text. */
const REWRITTEN_EXTRA_VAR = 'e2e-legacy-extra-var-Hm63';
const REWRITTEN_EXTRA_VARS = `{"cpu_ratio": 1.0, "db_password": "${REWRITTEN_EXTRA_VAR}"}`;

/** The survey fields the form submits: the editor's hidden input. */
async function submittedFields(page: Page): Promise<Record<string, unknown>[]> {
  return JSON.parse(await page.locator('#survey-fields-json').inputValue()) as Record<string, unknown>[];
}

/** Opens the template's edit form, once the editor shows its rows; the template id. */
async function openEditor(page: Page, name: string): Promise<string> {
  const id = await openJobTemplate(page, name);
  await page.goto(`/job-template/update?id=${id}`);
  await expect(page.locator('#survey-rows .survey-row').first()).toBeVisible();
  return id;
}

/** The editor row whose name input holds `name`. */
async function editorRow(page: Page, name: string): Promise<Locator> {
  const rows = page.locator('#survey-rows .survey-row');
  const index = await rows.evaluateAll(
    (elements, wanted) => elements.findIndex((row) => (row.querySelector('.sf-name') as HTMLInputElement).value === wanted),
    name,
  );
  expect(index, `an editor row named ${name}`).toBeGreaterThanOrEqual(0);
  return rows.nth(index);
}

async function scriptRan(page: Page): Promise<unknown> {
  return page.evaluate(() => (window as unknown as { __e2eSurveyXss?: number }).__e2eSurveyXss);
}

/** The text the form's Extra Vars editor (CodeMirror, web/js/extra-vars-editor.js) shows. */
async function extraVarsEditorText(page: Page): Promise<string> {
  const editor = page.locator('.field-jobtemplate-extra_vars .CodeMirror');
  await expect(editor).toBeVisible();
  return editor.evaluate((element) => (element as unknown as { CodeMirror: { getValue(): string } }).CodeMirror.getValue());
}

/** Saves the open template form; the form has to lead to the template page. The request it posted. */
async function saveTemplateForm(page: Page, id: string): Promise<Request> {
  const [request] = await Promise.all([
    page.waitForRequest((r) => r.method() === 'POST' && r.url().includes(`/job-template/update?id=${id}`)),
    page.locator('#jt-form button[type="submit"]', { hasText: 'Save Changes' }).click(),
  ]);
  await expect(page, 'the edit is saved, not sent back to the form').toHaveURL(new RegExp(`/job-template/view\\?id=${id}$`), { timeout: 15_000 });
  return request;
}

test.describe('Job template survey editor', () => {
  // Regression: the editor wrote the survey into its inline script with
  // Json::encode, which leaves "</script>" as it is: a label could end the
  // script and run its own (stored XSS for everyone who edits the template).
  test('survey text that ends a script element stays text in the editor and on the launch page', async ({ page }) => {
    const dialogs: string[] = [];
    page.on('dialog', (dialog) => {
      dialogs.push(dialog.message());
      void dialog.dismiss();
    });

    const id = await openJobTemplate(page, XSS_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    // Before: the label ended the editor's script, ran its own, and the editor showed no rows.
    expect(await scriptRan(page), 'survey text ran as a script').toBeUndefined();
    const field = await editorRow(page, 'deploy_target');
    await expect(field.locator('.sf-label')).toHaveValue(XSS_LABEL);
    await expect(field.locator('.sf-default')).toHaveValue(XSS_DEFAULT);
    await expect(field.locator('.sf-hint')).toHaveValue(XSS_HINT);
    expect(await submittedFields(page)).toEqual([
      expect.objectContaining({ name: 'deploy_target', label: XSS_LABEL, default: XSS_DEFAULT, hint: XSS_HINT }),
    ]);
    // Everything after the editor's script still renders.
    await expect(page.locator('#jt-form button[type="submit"]', { hasText: 'Save Changes' })).toBeVisible();

    await page.goto(`/job-template/launch?id=${id}`);
    await expect(page.locator('#launch-form label.form-label').first()).toContainText(XSS_LABEL);
    await expect(page.locator('#survey-deploy_target')).toHaveValue(XSS_DEFAULT);
    expect(await scriptRan(page)).toBeUndefined();
    expect(dialogs).toEqual([]);
  });

  // Regression: the editor showed a password field's stored default in clear
  // text, in its default input and in the page source.
  test('a password field offers no default, and its stored default never reaches the page', async ({ page }) => {
    await openEditor(page, LEGACY_TEMPLATE);
    await expectNotInPage(page, LEGACY_DEFAULT);

    const password = await editorRow(page, 'db_password');
    await expect(password.locator('.sf-type')).toHaveValue('password');
    await expect(password.locator('.sf-default')).toBeHidden();
    await expect(password.locator('.sf-default-none')).toHaveText('None: entered at launch');
    const host = await editorRow(page, 'db-host');
    await expect(host.locator('.sf-default')).toHaveValue('db.example.test');
    expect(await submittedFields(page)).toEqual([
      expect.objectContaining({ name: 'db-host', default: 'db.example.test' }),
      expect.objectContaining({ name: 'db_password', type: 'password', default: '' }),
    ]);
  });

  test('switching a field to password clears its default', async ({ page }) => {
    await openEditor(page, LEGACY_TEMPLATE);
    await page.locator('#survey-editor button', { hasText: 'Add Field' }).click();
    const added = page.locator('#survey-rows .survey-row').last();
    await added.locator('.sf-name').fill('api_token');
    await added.locator('.sf-default').fill('typed-before-the-type');
    await added.locator('.sf-type').selectOption('password');

    await expect(added.locator('.sf-default')).toBeHidden();
    await expect(added.locator('.sf-default')).toHaveValue('');
    await expect(added.locator('.sf-default-none')).toBeVisible();
    expect((await submittedFields(page)).find((field) => field.name === 'api_token'))
      .toEqual(expect.objectContaining({ type: 'password', default: '' }));

    // Back to text: the default input returns, empty.
    await added.locator('.sf-type').selectOption('text');
    await expect(added.locator('.sf-default')).toBeVisible();
    await expect(added.locator('.sf-default')).toHaveValue('');
  });

  // The survey rules apply to fields that are new or renamed: a template
  // saved before them keeps saving while its survey stays as it is. Its own
  // fixture: the save drops the password default, which the test above
  // checks never reaches the page.
  test('an edit that leaves a legacy survey alone saves', async ({ page }) => {
    const id = await openEditor(page, LEGACY_EDIT_TEMPLATE);
    await page.locator('#jobtemplate-description').fill(`E2E legacy survey, edited ${Date.now()}`);
    await saveTemplateForm(page, id);
    await expectFlash(page, 'success', `Template "${LEGACY_EDIT_TEMPLATE}" updated.`);

    await openEditor(page, LEGACY_EDIT_TEMPLATE);
    expect((await submittedFields(page)).map((field) => field.name)).toEqual(['db-host', 'db_password']);
  });

  // Regression: the form's Extra Vars editor wrote the stored JSON back on
  // load (JSON.stringify(JSON.parse(text)), so 1.0 became 1), and the rule
  // against password keys in Extra Vars took that for a change: every edit
  // of a template saved with such a key was refused, a new description too.
  // The editor now leaves the stored text alone: the edit posts it as stored
  // and saves, and the stored text is the same for the next run.
  test('a description-only edit of a template with a password key in its Extra Vars posts them unchanged and saves', async ({ page }) => {
    const id = await openJobTemplate(page, REWRITTEN_VARS_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    expect(await extraVarsEditorText(page), 'the stored Extra Vars').toBe(REWRITTEN_EXTRA_VARS);
    await expect(page.locator('#jobtemplate-extra_vars'), 'the field keeps the stored text').toHaveValue(REWRITTEN_EXTRA_VARS);

    const description = `E2E stored Extra Vars, edited ${Date.now()}`;
    await page.locator('#jobtemplate-description').fill(description);
    const posted = await saveTemplateForm(page, id);

    expect(new URLSearchParams(posted.postData() ?? '').get('JobTemplate[extra_vars]'), 'posted unchanged').toBe(REWRITTEN_EXTRA_VARS);
    await expectFlash(page, 'success', `Template "${REWRITTEN_VARS_TEMPLATE}" updated.`);
    // The template page still shows the password key's value only as the placeholder.
    await expectNotInPage(page, REWRITTEN_EXTRA_VAR);
    const shown = (await page.getByTestId('template-extra-vars').textContent()) ?? '';
    expect(JSON.parse(shown)).toEqual({ cpu_ratio: 1, db_password: PLACEHOLDER });

    // Saved: the new description, and the Extra Vars as they were stored.
    await page.goto(`/job-template/update?id=${id}`);
    await expect(page.locator('#jobtemplate-description')).toHaveValue(description);
    expect(await extraVarsEditorText(page)).toBe(REWRITTEN_EXTRA_VARS);
  });

  // Regression: survey errors went to the hidden survey input, which shows
  // no error, so a refused save looked like a page reload.
  test('a survey error shows next to the editor, and nothing is saved', async ({ page }) => {
    const id = await openEditor(page, LEGACY_TEMPLATE);
    await page.locator('#survey-editor button', { hasText: 'Add Field' }).click();
    await page.locator('#survey-rows .survey-row').last().locator('.sf-name').fill('release-tag');
    await page.locator('#jt-form button[type="submit"]', { hasText: 'Save Changes' }).click();

    const error = page.getByTestId('survey-fields-error');
    await expect(error).toBeVisible();
    await expect(error).toHaveText(
      'Survey field name "release-tag" is not a valid variable name: use letters, digits and underscores, '
      + 'and start with a letter or an underscore.',
    );
    await expect(page).toHaveURL(new RegExp(`/job-template/update\\?id=${id}$`));

    await openEditor(page, LEGACY_TEMPLATE);
    expect((await submittedFields(page)).map((field) => field.name)).toEqual(['db-host', 'db_password']);
  });

  // A password field's key in a template's Extra Vars is a stored default of
  // the password: the template page shows it only as the placeholder.
  test('the template page shows a password field\'s key in Default Extra Vars only as $encrypted$', async ({ page }) => {
    await openJobTemplate(page, EXTRA_VARS_TEMPLATE);
    await expectNotInPage(page, LEGACY_EXTRA_VAR);
    const shown = (await page.getByTestId('template-extra-vars').textContent()) ?? '';
    expect(JSON.parse(shown)).toEqual({ region: 'eu-central', db_password: PLACEHOLDER });
  });
});

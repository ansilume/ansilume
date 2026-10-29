import { test, expect, Page } from '@playwright/test';
import {
  expectFlash,
  fieldError,
  fillForm,
  getTableRowCount,
  notAnInteger,
  postJsonWithPageCsrf,
  showAnswer,
  submitForm,
  templateId,
} from '../../lib/helpers';
import { PAGE_TITLE } from '../../lib/selectors';
import { forgePost, optionValue } from '../../lib/scoping';
import {
  INVALID_CREDENTIAL_IDS,
  NEARLY_FIVE,
  NEARLY_THREE,
  TEMPLATE_DELETE_CONFIRM,
  additionalCredentialId,
  credentialIdsError,
  deleteOpenTemplate,
  nearlyId,
  newTemplateMembers,
  runsWhat,
  templateDeleted,
} from '../../lib/job-templates';

/**
 * Integer fields of the template form and their labels, each sent as the
 * JSON number 1e30. On PHP 8.5 the (int) cast of the credential answered
 * 500 ("The float 1.0E+30 is not representable as an int, cast occurred"),
 * as did those of the project, the inventory and the approval rule; the
 * form showed forks, like the other integer fields, as 1.0E+30.
 */
const BEYOND_INT_FIELDS = [['forks', 'Forks'], ['credential_id', 'Credential Id']] as const;

/**
 * Values of the template form posted as JSON that it saved or failed on
 * before (2.13.1 and the move to PHP 8.5 up to its fixes), each with its
 * field's label and whether the form shows the field empty again (a select
 * without an empty choice, such as the verbosity, shows its first one):
 *
 * - an empty list, which Yii's rules take for empty and skip, so the column
 *   stored it as 0: the credential and an admin's approval rule answered 500
 *   (their foreign keys refuse 0), the verbosity was saved as 0;
 * - a number that is no whole number, but which PHP writes as one with its
 *   14 significant digits, so the integer rule took it, and the column
 *   stored it cut: 2.9999999999999996 forks as 2, verbosity
 *   4.999999999999999 as 4.
 *
 * Each is refused now with the field's integer error
 * (JobTemplateFormPost::refusesLists(), JobTemplateController's rule check,
 * app\components\WholeNumberValidator).
 */
const REFUSED_VALUES = [
  { attribute: 'credential_id', value: '[]', label: 'Credential Id', slug: 'credential-list', emptied: true },
  { attribute: 'verbosity', value: '[]', label: 'Verbosity', slug: 'verbosity-list', emptied: false },
  { attribute: 'approval_rule_id', value: '[]', label: 'Approval rule', slug: 'rule-list', emptied: true },
  { attribute: 'forks', value: NEARLY_THREE, label: 'Forks', slug: 'forks-nearly-three', emptied: true },
  { attribute: 'verbosity', value: NEARLY_FIVE, label: 'Verbosity', slug: 'verbosity-nearly-five', emptied: false },
] as const;

/**
 * Additional credentials (credential_ids) of the template form posted as
 * JSON that are no credential IDs, but which PHP writes as an ID with its 14
 * significant digits, each built from the ID of a credential the form
 * offers: the form read them with filter_var(), which took
 * 2.9999999999999996 for credential #3 and a credential's ID followed by a
 * fraction in its 15th digit for that credential, which it saved. Each is
 * refused now with INVALID_CREDENTIAL_IDS under the additional credentials
 * (JobTemplateCredentialService::checkedIds() reads an entry as
 * SubmittedId::of() does).
 */
const NOT_CREDENTIAL_IDS: ReadonlyArray<{ label: string; slug: string; entry: (credentialId: string) => string }> = [
  { label: NEARLY_THREE, slug: 'nearly-three', entry: () => NEARLY_THREE },
  { label: 'a credential ID with a fraction in its 15th digit', slug: 'nearly-id', entry: nearlyId },
];

/**
 * Creates the job template `name` like e2e-template (e2e-project, site.yml,
 * e2e-inventory, the first runner group, verbosity 1, 5 forks) through a real
 * form post; its ID. Its page opens.
 */
async function createTemplate(page: Page, name: string): Promise<string> {
  await page.goto('/job-template/create');
  const fields = {
    'JobTemplate[name]': name,
    'JobTemplate[project_id]': await optionValue(page, '#jobtemplate-project_id', 'e2e-project'),
    'JobTemplate[playbook]': 'site.yml',
    'JobTemplate[inventory_id]': await optionValue(page, '#jobtemplate-inventory_id', 'e2e-inventory'),
    'JobTemplate[runner_group_id]': (await page.locator('#jobtemplate-runner_group_id option:not([value=""])').first().getAttribute('value')) ?? '',
    'JobTemplate[verbosity]': '1',
    'JobTemplate[forks]': '5',
    'JobTemplate[timeout_minutes]': '30',
  };
  await page.goto('/job-template/index');
  await forgePost(page, '/job-template/create', fields, /\/job-template\/view\?id=\d+$/);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

test.describe('Job Templates CRUD', () => {
  test('index page lists templates', async ({ page }) => {
    await page.goto('/job-template/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/template/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view template detail', async ({ page }) => {
    await page.goto('/job-template/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/template|playbook|project/i);
  });

  test('create job template', async ({ page }) => {
    await page.goto('/job-template/create');
    await fillForm(page, 'jobtemplate', {
      name: 'e2e-crud-template',
      playbook: 'test.yml',
      description: 'Created by E2E test',
    });
    // Select project, inventory, runner group from dropdowns
    const projectSelect = page.locator('#jobtemplate-project_id');
    if (await projectSelect.isVisible()) {
      // Select the first available option that's not empty
      const options = await projectSelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await projectSelect.selectOption(value);
      }
    }
    const inventorySelect = page.locator('#jobtemplate-inventory_id');
    if (await inventorySelect.isVisible()) {
      const options = await inventorySelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await inventorySelect.selectOption(value);
      }
    }
    const runnerGroupSelect = page.locator('#jobtemplate-runner_group_id');
    if (await runnerGroupSelect.isVisible()) {
      const options = await runnerGroupSelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await runnerGroupSelect.selectOption(value);
      }
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update job template', async ({ page }) => {
    // The index defaults to name-sort, which pushes the freshly-created
    // e2e-crud-template past page 1 when many Demo/e2e templates share
    // a common prefix. Force id-desc so the newest row stays on page 1.
    await page.goto('/job-template/index?sort=-id');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-template' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#jobtemplate-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete job template', async ({ page }) => {
    // The newest e2e-crud-template, the one this file created.
    await page.goto('/job-template/index?sort=-id');
    await page.locator('table.table tbody tr', { hasText: 'e2e-crud-template' }).first()
      .locator('a', { hasText: /^e2e-crud-template$/ }).click();
    // The confirm names what the delete does besides; this template has neither.
    expect(await deleteOpenTemplate(page)).toBe(TEMPLATE_DELETE_CONFIRM);
    await expectFlash(page, 'success', templateDeleted('e2e-crud-template', 0, 0));
  });

  // Regression: the template form posted as JSON (the request's JSON parser
  // serves the web forms too; a script sends the CSRF token as a header) with
  // a number beyond the int range answered 500 on PHP 8.5, or showed the
  // number as 1.0E+30 in its field (BEYOND_INT_FIELDS). Now the form is shown
  // again with the field's error and the field empty, and nothing is saved
  // (JobTemplateController::renderRejected()).
  for (const [attribute, label] of BEYOND_INT_FIELDS) {
    test(`a create posted as JSON with ${attribute} 1e30 shows the field's error with the field empty, and creates nothing`, async ({ page }) => {
      const name = `e2e-json-${attribute.replace('_', '-')}-template`;
      await page.goto('/job-template/create');
      const members = await newTemplateMembers(page, name);

      const answer = await postJsonWithPageCsrf(page, '/job-template/create', `{"JobTemplate": {${members}, "${attribute}": 1e30}}`);

      expect(answer.status(), 'the form again, not a server error').toBe(200);
      await showAnswer(page, answer);
      await expect(fieldError(page, 'jobtemplate', attribute)).toHaveText(notAnInteger(label));
      await expect(page.locator(`#jobtemplate-${attribute}`), 'the field empty, not 1.0E+30').toHaveValue('');
      await page.goto('/job-template/index?sort=-id');
      await expect(page.locator('#template-table tbody')).not.toContainText(name);
    });

    test(`an update posted as JSON with ${attribute} 1e30 shows the field's error with the field empty, and changes nothing`, async ({ page }) => {
      const id = await templateId(page, 'e2e-template');
      await page.goto(`/job-template/view?id=${id}`);
      const before = await runsWhat(page);
      await page.goto(`/job-template/update?id=${id}`);

      const answer = await postJsonWithPageCsrf(page, `/job-template/update?id=${id}`, `{"JobTemplate": {"playbook": "e2e-json-changed.yml", "${attribute}": 1e30}}`);

      expect(answer.status(), 'the form again, not a server error').toBe(200);
      await showAnswer(page, answer);
      await expect(fieldError(page, 'jobtemplate', attribute)).toHaveText(notAnInteger(label));
      await expect(page.locator(`#jobtemplate-${attribute}`), 'the field empty, not 1.0E+30').toHaveValue('');
      await page.goto(`/job-template/view?id=${id}`);
      expect(await runsWhat(page), 'not even the playbook was saved').toEqual(before);
    });
  }

  // Regression: the template form posted as JSON saved or failed on the
  // values of REFUSED_VALUES: an empty list as the credential or as an
  // admin's approval rule answered 500, one as the verbosity was saved as
  // verbosity 0, forks 2.9999999999999996 were saved as 2 and verbosity
  // 4.999999999999999 as 4. Now the form is shown again with the field's
  // integer error, and nothing is saved.
  for (const field of REFUSED_VALUES) {
    test(`a create posted as JSON with ${field.attribute} ${field.value} shows the field's error, and creates nothing`, async ({ page }) => {
      const name = `e2e-json-${field.slug}-template`;
      await page.goto('/job-template/create');
      const members = await newTemplateMembers(page, name);

      const answer = await postJsonWithPageCsrf(page, '/job-template/create', `{"JobTemplate": {${members}, "${field.attribute}": ${field.value}}}`);

      expect(answer.status(), 'the form again, not a server error').toBe(200);
      await showAnswer(page, answer);
      await expect(fieldError(page, 'jobtemplate', field.attribute)).toHaveText(notAnInteger(field.label));
      if (field.emptied) {
        await expect(page.locator(`#jobtemplate-${field.attribute}`), 'the field empty').toHaveValue('');
      }
      await page.goto('/job-template/index?sort=-id');
      await expect(page.locator('#template-table tbody')).not.toContainText(name);
    });

    // On a template of its own: the old code saved some of these values,
    // with the playbook, which no other spec should meet on e2e-template.
    test(`an update posted as JSON with ${field.attribute} ${field.value} shows the field's error, and changes nothing`, async ({ page }) => {
      const id = await createTemplate(page, `e2e-json-${field.slug}-update-template`);
      try {
        const before = await runsWhat(page);
        await page.goto(`/job-template/update?id=${id}`);

        const answer = await postJsonWithPageCsrf(page, `/job-template/update?id=${id}`, `{"JobTemplate": {"playbook": "e2e-json-changed.yml", "${field.attribute}": ${field.value}}}`);

        expect(answer.status(), 'the form again, not a server error').toBe(200);
        await showAnswer(page, answer);
        await expect(fieldError(page, 'jobtemplate', field.attribute)).toHaveText(notAnInteger(field.label));
        if (field.emptied) {
          await expect(page.locator(`#jobtemplate-${field.attribute}`), 'the field empty').toHaveValue('');
        }
        await page.goto(`/job-template/view?id=${id}`);
        expect(await runsWhat(page), 'not even the playbook was saved').toEqual(before);
      } finally {
        await page.goto(`/job-template/view?id=${id}`);
        await deleteOpenTemplate(page);
      }
    });
  }

  // Regression: the template form posted as JSON saved the additional
  // credentials of NOT_CREDENTIAL_IDS as the credential PHP writes them as.
  // Now the form is shown again with the error under the additional
  // credentials, none of them checked, and nothing is saved.
  for (const field of NOT_CREDENTIAL_IDS) {
    test(`a create posted as JSON with credential_ids [${field.label}] shows the credentials' error, and creates nothing`, async ({ page }) => {
      const name = `e2e-json-credentials-${field.slug}-template`;
      await page.goto('/job-template/create');
      const members = await newTemplateMembers(page, name);
      const entry = field.entry(await additionalCredentialId(page));

      const answer = await postJsonWithPageCsrf(page, '/job-template/create', `{"JobTemplate": {${members}}, "credential_ids": [${entry}]}`);

      expect(answer.status(), 'the form again, not a server error').toBe(200);
      await showAnswer(page, answer);
      await expect(credentialIdsError(page)).toHaveText(INVALID_CREDENTIAL_IDS);
      await expect(page.locator('#jt-form input[name="credential_ids[]"]:checked'), 'no credential checked').toHaveCount(0);
      await page.goto('/job-template/index?sort=-id');
      await expect(page.locator('#template-table tbody')).not.toContainText(name);
    });

    // On a template of its own: the old code saved the credential, with the playbook.
    test(`an update posted as JSON with credential_ids [${field.label}] shows the credentials' error, and changes nothing`, async ({ page }) => {
      const id = await createTemplate(page, `e2e-json-credentials-${field.slug}-update-template`);
      try {
        const before = await runsWhat(page);
        await page.goto(`/job-template/update?id=${id}`);
        const entry = field.entry(await additionalCredentialId(page));

        const answer = await postJsonWithPageCsrf(page, `/job-template/update?id=${id}`, `{"JobTemplate": {"playbook": "e2e-json-changed.yml"}, "credential_ids": [${entry}]}`);

        expect(answer.status(), 'the form again, not a server error').toBe(200);
        await showAnswer(page, answer);
        await expect(credentialIdsError(page)).toHaveText(INVALID_CREDENTIAL_IDS);
        await page.goto(`/job-template/view?id=${id}`);
        expect(await runsWhat(page), 'neither the playbook nor a credential was saved').toEqual(before);
      } finally {
        await page.goto(`/job-template/view?id=${id}`);
        await deleteOpenTemplate(page);
      }
    });
  }

  // Regression: the template form posted as JSON refused an additional
  // credential sent as "0<ID>", which the integer rule reads as the ID. It is
  // that credential.
  test('a create posted as JSON with credential_ids ["0<ID>"] attaches that credential', async ({ page }) => {
    await page.goto('/job-template/create');
    const members = await newTemplateMembers(page, 'e2e-json-credentials-leading-zero-template');
    const credential = await additionalCredentialId(page);

    const answer = await postJsonWithPageCsrf(page, '/job-template/create', `{"JobTemplate": {${members}}, "credential_ids": ["0${credential}"]}`);

    expect(answer.url(), 'the template page, not the form again').toMatch(/\/job-template\/view\?id=\d+$/);
    await page.goto(answer.url());
    try {
      expect((await runsWhat(page)).credentialIds).toBe(`${credential}:additional`);
    } finally {
      await deleteOpenTemplate(page);
    }
  });
});

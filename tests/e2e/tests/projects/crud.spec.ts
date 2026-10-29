import { test, expect, Page } from '@playwright/test';
import {
  deleteByRowText,
  expectFlash,
  fieldError,
  fillForm,
  getTableRowCount,
  postJsonWithPageCsrf,
  showAnswer,
  submitForm,
} from '../../lib/helpers';
import { jobTemplateDetail, queuedJobOf } from '../../lib/jobs';
import { NOT_A_CREDENTIAL_ID, gitProjectWithCredential, projectId } from '../../lib/projects';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

/** The text of the Description card of the open project page; '' without one. */
async function shownDescription(page: Page): Promise<string> {
  const card = page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Description$/ }) }).locator('.card-body');
  return (await card.count()) > 0 ? ((await card.textContent()) ?? '').trim() : '';
}

test.describe('Projects CRUD', () => {
  test('index page lists projects', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/project/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view project detail', async ({ page }) => {
    await page.goto('/project/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/project|scm|status/i);
  });

  test('create manual project', async ({ page }) => {
    await page.goto('/project/create');
    await fillForm(page, 'project', {
      name: 'e2e-crud-project',
      description: 'Created by E2E test',
    });
    // Select manual SCM type
    const scmSelect = page.locator('#project-scm_type');
    if (await scmSelect.isVisible()) {
      await scmSelect.selectOption('manual');
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('validation rejects empty name', async ({ page }) => {
    await page.goto('/project/create');
    await fillForm(page, 'project', { name: '' });
    await page.locator('#page-content button[type="submit"]').click();
    await expect(page.locator('.invalid-feedback, .help-block-error, .has-error')).toBeVisible({
      timeout: 5_000,
    });
  });

  test('update project', async ({ page }) => {
    await page.goto('/project/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-project' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#project-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  // Regression: issue #15 — deleting a project that still has job templates
  // must show a danger flash instead of throwing an FK constraint exception.
  test('delete project with templates is refused (issue #15 regression)', async ({ page }) => {
    // The seeded e2e-project has e2e-template referencing it.
    page.on('dialog', (d) => d.accept());
    await page.goto('/project/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-project' }).first();
    await expect(row).toBeVisible({ timeout: 5_000 });
    await row.locator('a').first().click();
    await page.waitForLoadState('domcontentloaded');

    // Submit the delete form on the view page.
    const submitted = await page.evaluate(() => {
      const root = document.getElementById('page-content') || document.body;
      const btn = Array.from(root.querySelectorAll('form button[type="submit"], form input[type="submit"]'))
        .find((el) => /delete/i.test((el as HTMLElement).innerText || (el as HTMLInputElement).value || ''));
      if (btn) {
        const form = (btn as HTMLButtonElement).closest('form') as HTMLFormElement | null;
        if (form) { form.submit(); return true; }
      }
      return false;
    });
    expect(submitted).toBe(true);
    await page.waitForLoadState('networkidle', { timeout: 10_000 });

    // Must show danger flash with refusal, not an exception page.
    await expectFlash(page, 'danger', 'job template');
    // Project must still be listed.
    await page.goto('/project/index');
    await expect(page.locator('table.table tbody tr', { hasText: 'e2e-project' })).toBeVisible();
  });

  test('delete project', async ({ page }) => {
    await deleteByRowText(page, '/project/index', 'e2e-crud-project');
    await expectFlash(page, 'success');
  });

  // Regression: the project form posted as JSON (the request's JSON parser
  // serves the web forms too; a script sends the CSRF token as a header) with
  // an empty list as the repository credential answered 500: Yii's rules took
  // the list for empty and skipped it, the column stored it as 0, and its
  // foreign key refused that. The field's integer error is shown now, and
  // nothing is saved (Project::rules(), SubmittedInteger::isEmpty()).
  test('a create posted as JSON with an empty list as the repository credential shows the field\'s error, and creates nothing', async ({ page }) => {
    const name = 'e2e-json-credential-list-project';
    await page.goto('/project/create');

    const answer = await postJsonWithPageCsrf(page, '/project/create', gitProjectWithCredential(name, '[]'));

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(fieldError(page, 'project', 'scm_credential_id')).toHaveText(NOT_A_CREDENTIAL_ID);
    await expect(page.locator('#project-scm_credential_id'), 'no credential chosen').toHaveValue('');
    await page.goto('/project/index');
    await expect(page.locator('#project-table tbody'), 'no project was created').not.toContainText(name);
  });

  // Regression: the same post on an update answered 500 too. On e2e-project,
  // a manual project, the form hides the Git fields and names their errors
  // above itself.
  test('an update posted as JSON with an empty list as the repository credential shows the error, and changes nothing', async ({ page }) => {
    const id = await projectId(page, 'e2e-project');
    await page.goto(`/project/view?id=${id}`);
    const before = await shownDescription(page);
    await page.goto(`/project/update?id=${id}`);

    const answer = await postJsonWithPageCsrf(page, `/project/update?id=${id}`, '{"Project": {"description": "e2e-json-changed", "scm_credential_id": []}}');

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(page.locator('#project-git-field-errors li')).toHaveText([NOT_A_CREDENTIAL_ID]);
    await expect(fieldError(page, 'project', 'scm_credential_id')).toHaveText(NOT_A_CREDENTIAL_ID);
    await page.goto(`/project/view?id=${id}`);
    expect(await shownDescription(page), 'not even the description was saved').toBe(before);
  });

  test('delete project whose templates were soft-deleted (integrity violation regression)', async ({ page, request, baseURL }) => {
    // Seeded e2e-softdel-project has one template that was deleted in the UI
    // (soft-deleted row still references the project). Deleting the project
    // used to hit the RESTRICT foreign key and render a 500 error page.
    //
    // Regression: deleting the project left a waiting job of its deleted
    // template queued for good: the purge of the template cleared the job's
    // template, and no runner claims a job without one. The delete cancels
    // such jobs first (services/ProjectDeletionService.php). The job is made
    // right before the delete, as one that waited when its template was
    // deleted: the maintenance run cancels a seeded one long before this spec.
    const jobId = await queuedJobOf(request, baseURL, 'e2e-softdel-template');
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Queued');
    await expect(jobTemplateDetail(page)).toHaveText('e2e-softdel-template (deleted)');

    await deleteByRowText(page, '/project/index', 'e2e-softdel-project');
    await expectFlash(page, 'success');
    await page.goto('/project/index');
    await expect(page.locator('table.table tbody tr', { hasText: 'e2e-softdel-project' })).toHaveCount(0);

    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Canceled');
    await expect(page.locator('#detail-status')).toHaveText('Canceled');
    await expect(jobTemplateDetail(page), 'the template was purged').toHaveText('—');
  });
});

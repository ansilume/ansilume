import { test, expect, Page } from '@playwright/test';
import { deleteByRowText, postWithPageCsrf } from '../../lib/helpers';
import { forgePost, indexRows, rowsWhere } from '../../lib/scoping';
import { FORM_SUBMIT } from '../../lib/selectors';

/** models/Project.php Project::SSH_URL_NEEDS_SSH_KEY. */
const SSH_URL_NEEDS_SSH_KEY = 'SSH URLs require an SSH Key credential.';

/** models/Project.php Project::GIT_FIELDS_REFUSED, above the errors of hidden Git fields. */
const GIT_FIELDS_REFUSED = 'Not saved: the hidden Git fields have errors. Choose Git under Scm Type to see and correct them, then switch back and save.';

/** The error of yii\validators\ExistValidator on the Credential field (its label is "Scm Credential Id"). */
const CREDENTIAL_INVALID = 'Scm Credential Id is invalid.';

/** The project these tests create: none of that name may exist after them. */
const NAME = 'e2e-git-fields-project';

/** The credential the deleted-credential test creates and deletes. */
const CREDENTIAL = 'e2e-git-fields-credential';

/** The token credential commands/E2eController.php seeds, as the Credential field lists it. */
const SEEDED_TOKEN = 'e2e-credential (Token)';

/** An SSH URL, which needs an SSH Key credential for a git project. No sync reaches it. */
const SSH_URL = 'git@127.0.0.1:e2e/git-fields.git';

/** An HTTPS URL. No sync reaches it. */
const HTTPS_URL = 'https://127.0.0.1:1/e2e/git-fields.git';

/** The local path of the manual projects: saving one needs no such directory. */
const LOCAL_PATH = '/opt/playbooks/e2e-git-fields';

/** The address of the project page the browser is on, after a save. */
const PROJECT_PAGE = /\/project\/view\?id=\d+$/;

/** The box above the form with the errors of hidden Git fields. */
const HIDDEN_ERRORS = '#project-git-field-errors';

/** The Credential field's error (yii\widgets\ActiveField writes it in .help-block). */
function credentialError(page: Page) {
  return page.locator('.field-project-scm_credential_id .help-block');
}

/** The SCM Type the project page shows. */
function scmType(page: Page) {
  return page.locator('#page-content dt:text-is("SCM Type") + dd');
}

/** The paths of the POST requests the page sends from now on. */
function recordPosts(page: Page): string[] {
  const posts: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST') {
      posts.push(new URL(request.url()).pathname);
    }
  });
  return posts;
}

async function projectsNamed(page: Page): Promise<number> {
  return rowsWhere(await indexRows(page, '/project/index'), 1, NAME).length;
}

/** Removes every project named NAME and every credential named CREDENTIAL, left by an earlier run. */
async function removeFixtures(page: Page) {
  for (let left = await projectsNamed(page); left > 0; left--) {
    await deleteByRowText(page, '/project/index', NAME);
  }
  for (const row of rowsWhere(await indexRows(page, '/credential/index'), 1, CREDENTIAL)) {
    const answer = await postWithPageCsrf(page, `/credential/delete?id=${row.id}`);
    expect(answer.status(), `credential #${row.id} deleted`).toBe(302);
  }
}

/**
 * Fills the New Project form with Git, an SSH URL and the seeded Token
 * credential, which a git project with an SSH URL may not use, then
 * switches it to Manual (no SCM), which hides the Git fields with what
 * they hold, and fills the local path.
 */
async function fillManualProjectWithACredentialChosenUnderGit(page: Page) {
  await page.goto('/project/create');
  await page.locator('#project-name').fill(NAME);
  await page.locator('#project-scm_type').selectOption('git');
  await page.locator('#project-scm_url').fill(SSH_URL);
  await page.locator('#project-scm_credential_id').selectOption({ label: SEEDED_TOKEN });
  await page.locator('#project-scm_type').selectOption('manual');
  await expect(page.locator('#project-scm_credential_id')).toBeHidden();
  await page.locator('#project-local_path').fill(LOCAL_PATH);
}

// regression: the credential type was checked for every project. With an SSH
// URL and a Token credential chosen under Git and the type switched to Manual
// (no SCM), Create Project was refused with "SSH URLs require an SSH Key
// credential." under the hidden Credential field: nothing visible, nothing
// saved. It is checked for git projects only now, and the form shows the
// errors that hidden Git fields still get above itself.
test.describe('Project Git fields', () => {
  test.beforeEach(async ({ page }) => {
    await removeFixtures(page);
  });

  test.afterEach(async ({ page }) => {
    await removeFixtures(page);
  });

  test('regression: a credential chosen under Git does not stop a manual project from being created', async ({ page }) => {
    await fillManualProjectWithACredentialChosenUnderGit(page);
    const posts = recordPosts(page);

    await page.locator(FORM_SUBMIT).click();

    await expect(page, 'saved: the browser is on the new project').toHaveURL(PROJECT_PAGE);
    expect(posts, 'the form was sent once').toEqual(['/project/create']);
    await expect(page.locator('#page-content h2')).toHaveText(NAME);
    await expect(scmType(page)).toHaveText('MANUAL');
    expect(await projectsNamed(page), 'the project is created').toBe(1);
  });

  test('a manual project with such a credential is saved in its Edit form, and switched to Git it shows the credential error', async ({ page }) => {
    await fillManualProjectWithACredentialChosenUnderGit(page);
    await page.locator(FORM_SUBMIT).click();
    await expect(page).toHaveURL(PROJECT_PAGE);
    const projectPage = page.url();
    const editForm = projectPage.replace('/project/view', '/project/update');

    // Its Edit form sends the stored Git fields back, hidden: the project is saved.
    await page.goto(editForm);
    await expect(page.locator('#project-scm_credential_id')).toBeHidden();
    await page.locator('#project-description').fill('e2e: saved with hidden Git fields');
    await page.locator(FORM_SUBMIT).click();
    await expect(page, 'saved: the browser is on the project').toHaveURL(projectPage);
    await expect(page.locator('#page-content')).toContainText('e2e: saved with hidden Git fields');

    // Switched to Git, the credential is checked: the server refuses it, under the Credential field.
    await page.goto(editForm);
    await page.locator('#project-scm_type').selectOption('git');
    await expect(page.locator('#project-scm_credential_id')).toBeVisible();
    const [answer] = await Promise.all([
      page.waitForResponse((response) => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/project/update'),
      page.locator(FORM_SUBMIT).click(),
    ]);
    expect(answer.status(), 'the form is answered again, not a redirect to the project').toBe(200);
    await page.waitForLoadState('domcontentloaded');
    await expect(credentialError(page)).toBeVisible();
    await expect(credentialError(page)).toHaveText(SSH_URL_NEEDS_SSH_KEY);
    await expect(page.locator('.field-project-scm_credential_id')).toHaveClass(/\bhas-error\b/);
    await expect(page.locator(HIDDEN_ERRORS), 'nothing above the form: the field shows its error').toHaveCount(0);

    await page.goto(projectPage);
    await expect(scmType(page), 'the project is still manual').toHaveText('MANUAL');
  });

  // regression: the error of a hidden Git field was shown only under that
  // field, so a credential deleted while the form was open kept a manual
  // project from being created without a visible reason.
  test('regression: the error of a hidden Git field is shown above the form, and the project is created once it is corrected', async ({ page, context }) => {
    await page.goto('/credential/create');
    await page.locator('#credential-name').fill(CREDENTIAL);
    await page.locator('#credential-type').selectOption('token');
    await page.locator('input[name="secrets[token]"]').fill('e2e-git-fields-token');
    await page.locator(FORM_SUBMIT).click();
    await expect(page).toHaveURL(/\/credential\/view\?id=\d+$/);

    await page.goto('/project/create');
    await page.locator('#project-name').fill(NAME);
    await page.locator('#project-scm_type').selectOption('git');
    await page.locator('#project-scm_url').fill(HTTPS_URL);
    await page.locator('#project-scm_credential_id').selectOption({ label: `${CREDENTIAL} (Token)` });
    await page.locator('#project-scm_type').selectOption('manual');
    await page.locator('#project-local_path').fill(LOCAL_PATH);

    // The credential is deleted while the form is open.
    const other = await context.newPage();
    await deleteByRowText(other, '/credential/index', CREDENTIAL);
    await other.close();

    await page.locator(FORM_SUBMIT).click();

    await expect(page, 'the form is shown again').toHaveURL(/\/project\/create$/);
    const errors = page.locator(HIDDEN_ERRORS);
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(GIT_FIELDS_REFUSED);
    await expect(errors.locator('li')).toHaveText([CREDENTIAL_INVALID]);
    await expect(page.locator('#project-scm_credential_id')).toBeHidden();

    // Git shows the Credential field with its error; without a credential, and Manual again, the project is created.
    await page.locator('#project-scm_type').selectOption('git');
    await expect(credentialError(page)).toBeVisible();
    await expect(credentialError(page)).toHaveText(CREDENTIAL_INVALID);
    await page.locator('#project-scm_credential_id').selectOption('');
    await page.locator('#project-scm_type').selectOption('manual');
    await page.locator(FORM_SUBMIT).click();

    await expect(page, 'saved: the browser is on the new project').toHaveURL(PROJECT_PAGE);
    await expect(scmType(page)).toHaveText('MANUAL');
    expect(await projectsNamed(page), 'one project: the refused attempt created none').toBe(1);
  });

  // regression: the browser checked the hidden Git fields of a manual project
  // too. It counts the length of a field in UTF-16 code units, the server in
  // characters, so a stored branch of 100 characters outside the Basic
  // Multilingual Plane (200 code units, within the 128 characters of the
  // column) kept its Edit form from being sent, with the error on the hidden
  // Branch field.
  test('regression: a manual project whose hidden branch is longer in the browser than on the server is saved in its Edit form', async ({ page }) => {
    const branch = '\u{1F600}'.repeat(100);
    await page.goto('/project/index');
    // A manual project stored with such a branch, as the API can store one.
    await forgePost(page, '/project/create', {
      'Project[name]': NAME,
      'Project[scm_type]': 'manual',
      'Project[scm_branch]': branch,
      'Project[local_path]': LOCAL_PATH,
    }, PROJECT_PAGE);
    const projectPage = page.url();

    await page.goto(projectPage.replace('/project/view', '/project/update'));
    await expect(page.locator('#project-scm_branch')).toBeHidden();
    await expect(page.locator('#project-scm_branch')).toHaveValue(branch);
    await page.locator('#project-description').fill('e2e: saved with a long hidden branch');
    const posts = recordPosts(page);
    await page.locator(FORM_SUBMIT).click();

    await expect.poll(() => posts, { message: 'the browser sent the form' }).toEqual(['/project/update']);
    await expect(page, 'saved: the browser is on the project').toHaveURL(projectPage);
    await expect(page.locator('#page-content')).toContainText('e2e: saved with a long hidden branch');
  });
});

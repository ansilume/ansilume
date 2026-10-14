import { test, expect, Page } from '@playwright/test';
import { deleteByRowText } from '../../lib/helpers';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { FORM_SUBMIT } from '../../lib/selectors';

/** models/Project.php Project::BRANCH_FORMAT. */
const BRANCH_FORMAT = 'The branch cannot start with "-" or contain whitespace.';

/** The project these tests try to create: none of that name may exist after them. */
const NAME = 'e2e-branch-format-project';

/** Any git URL: the form is refused before a sync could reach it. */
const SCM_URL = 'https://127.0.0.1:1/e2e/branch-format.git';

/** The local path of the manual projects: saving one needs no such directory. */
const LOCAL_PATH = '/opt/playbooks/e2e-branch-format';

/** The address of the project page the browser is on, after a save. */
const PROJECT_PAGE = /\/project\/view\?id=\d+$/;

/** The Branch field's error (yii\widgets\ActiveField writes it in .help-block). */
function branchError(page: Page) {
  return page.locator('.field-project-scm_branch .help-block');
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

/** Removes every project named NAME: left by an earlier run whose save was not refused. */
async function removeProjects(page: Page) {
  for (let left = await projectsNamed(page); left > 0; left--) {
    await deleteByRowText(page, '/project/index', NAME);
  }
}

async function fillNewProject(page: Page, branch: string) {
  await page.goto('/project/create');
  await page.locator('#project-name').fill(NAME);
  await page.locator('#project-scm_type').selectOption('git');
  await page.locator('#project-scm_url').fill(SCM_URL);
  await page.locator('#project-scm_branch').fill(branch);
}

/**
 * Fills the New Project form with Git and `branch`, a branch the form
 * refuses for a git project, then switches it to Manual (no SCM), which
 * hides the Branch field with the branch and its error in it.
 */
async function fillManualProjectWithAHiddenBranch(page: Page, branch: string) {
  await fillNewProject(page, branch);
  await page.locator('#project-scm_branch').blur();
  await expect(branchError(page)).toHaveText(BRANCH_FORMAT);
  await page.locator('#project-scm_type').selectOption('manual');
  await expect(page.locator('#project-scm_branch')).toBeHidden();
  await page.locator('#project-local_path').fill(LOCAL_PATH);
}

/** Creates the manual project of fillManualProjectWithAHiddenBranch(): the address of its Edit form. */
async function createManualProjectWithAHiddenBranch(page: Page, branch: string): Promise<string> {
  await fillManualProjectWithAHiddenBranch(page, branch);
  await page.locator(FORM_SUBMIT).click();
  await expect(page).toHaveURL(PROJECT_PAGE);

  return page.url().replace('/project/view', '/project/update');
}

/** The SCM Type the project page shows. */
function scmType(page: Page) {
  return page.locator('#page-content dt:text-is("SCM Type") + dd');
}

// regression: the New Project form (and the API) saved a branch that starts
// with "-", which git read as one of its options up to 2.10.1 (such as
// --upload-pack=<command>); since 2.11.0 git takes it for a branch name and
// every sync of the project fails. Project::BRANCH_FORMAT refuses it, and
// whitespace, for git projects, in the browser and on the server. A manual
// project's branch never reaches git, and the form hides it.
test.describe('Project branch format', () => {
  test.beforeEach(async ({ page }) => {
    await removeProjects(page);
  });

  test.afterEach(async ({ page }) => {
    await removeProjects(page);
  });

  test('regression: the form refuses a branch that starts with "-" in the browser, and nothing is created', async ({ page }) => {
    await fillNewProject(page, '-x');
    const posts = recordPosts(page);

    await page.locator(FORM_SUBMIT).click();

    await expect(branchError(page)).toHaveText(BRANCH_FORMAT);
    await expect(page.locator('.field-project-scm_branch')).toHaveClass(/\bhas-error\b/);
    await expect(page).toHaveURL(/\/project\/create$/);

    // Whitespace is refused too; a branch without either clears the error.
    await page.locator('#project-scm_branch').fill('feature x');
    await page.locator('#project-scm_branch').blur();
    await expect(branchError(page)).toHaveText(BRANCH_FORMAT);
    await page.locator('#project-scm_branch').fill('feature/x');
    await page.locator('#project-scm_branch').blur();
    await expect(branchError(page)).toHaveText('');

    expect(posts, 'the browser sent nothing').toEqual([]);
    expect(await projectsNamed(page), 'nothing is created').toBe(0);
  });

  test('regression: the server refuses a branch that starts with "-" when the browser\'s check is passed by', async ({ page }) => {
    const branch = '--upload-pack=touch /tmp/e2e-branch-format';
    await fillNewProject(page, branch);

    // HTMLFormElement.submit() fires no submit event, so the form's own
    // check in the browser does not run: the server checks the branch.
    const [answer] = await Promise.all([
      page.waitForResponse((response) => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/project/create'),
      page.locator('#project-form').evaluate((form) => (form as HTMLFormElement).submit()),
    ]);
    expect(answer.status(), 'the form is answered again, not a redirect to a new project').toBe(200);
    await page.waitForLoadState('domcontentloaded');

    await expect(branchError(page)).toHaveText(BRANCH_FORMAT);
    await expect(page.locator('#project-scm_branch')).toHaveValue(branch);
    expect(await projectsNamed(page), 'nothing is created').toBe(0);
  });

  // regression (wave 5f): the branch rule applied whatever the type, and the
  // browser checks hidden fields too: with a branch typed under Git and the
  // type switched to Manual (no SCM), Create Project sent nothing and showed
  // no error, as the Branch field and its error were hidden.
  test('regression: a branch typed under Git does not stop a manual project from being created', async ({ page }) => {
    await fillManualProjectWithAHiddenBranch(page, 'feature x');
    const posts = recordPosts(page);

    await page.locator(FORM_SUBMIT).click();

    await expect(page, 'saved: the browser is on the new project').toHaveURL(PROJECT_PAGE);
    expect(posts, 'the form was sent').toEqual(['/project/create']);
    await expect(page.locator('#page-content h2')).toHaveText(NAME);
    await expect(scmType(page)).toHaveText('MANUAL');
    expect(await projectsNamed(page), 'the project is created').toBe(1);
  });

  test('a manual project with such a branch is saved in its Edit form, and switched to Git it shows the branch error', async ({ page }) => {
    const editForm = await createManualProjectWithAHiddenBranch(page, '-x');
    const projectPage = editForm.replace('/project/update', '/project/view');

    // Its Edit form sends the stored branch back, hidden: the project is saved.
    await page.goto(editForm);
    await expect(page.locator('#project-scm_branch')).toBeHidden();
    await page.locator('#project-description').fill('e2e: saved with a hidden branch');
    await page.locator(FORM_SUBMIT).click();
    await expect(page, 'saved: the browser is on the project').toHaveURL(projectPage);
    await expect(page.locator('#page-content')).toContainText('e2e: saved with a hidden branch');

    // Switched to Git, the branch is checked, under the Branch field it shows again.
    await page.goto(editForm);
    await page.locator('#project-scm_type').selectOption('git');
    await page.locator('#project-scm_url').fill(SCM_URL);
    const posts = recordPosts(page);
    await page.locator(FORM_SUBMIT).click();

    await expect(branchError(page)).toBeVisible();
    await expect(branchError(page)).toHaveText(BRANCH_FORMAT);
    await expect(page.locator('.field-project-scm_branch')).toHaveClass(/\bhas-error\b/);
    await expect(page.locator('#project-scm_branch')).toHaveValue('-x');
    await expect(page).toHaveURL(editForm);
    expect(posts, 'the browser sent nothing').toEqual([]);

    // The server refuses it too, and its answer shows the Branch field with the error.
    const [answer] = await Promise.all([
      page.waitForResponse((response) => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/project/update'),
      page.locator('#project-form').evaluate((form) => (form as HTMLFormElement).submit()),
    ]);
    expect(answer.status(), 'the form is answered again, not a redirect to the project').toBe(200);
    await page.waitForLoadState('domcontentloaded');
    await expect(branchError(page)).toBeVisible();
    await expect(branchError(page)).toHaveText(BRANCH_FORMAT);

    await page.goto(projectPage);
    await expect(scmType(page), 'the project is still manual').toHaveText('MANUAL');
  });
});

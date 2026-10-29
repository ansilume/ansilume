import { test, expect, Page } from '@playwright/test';
import { expectFlash, expectForbidden, fieldError, postJsonWithPageCsrf, showAnswer } from '../../lib/helpers';
import { NOT_A_CREDENTIAL_ID, gitProjectWithCredential } from '../../lib/projects';
import { asAdmin, forgePost } from '../../lib/scoping';
import { BTN_CREATE } from '../../lib/selectors';

// Fixtures from commands/E2eVaultScanSeeder.php: an open project (no team),
// scanned during the seed, so operator and viewer both see it.
const VAULT_PROJECT = 'e2e-vault-scan-project';
const VAULT_ENTRIES = 7;

/** Opens the vault scan project, paging through the list (newest first); returns its id. */
async function openVaultProject(page: Page): Promise<string> {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/project/index?page=${listPage}`);
    const link = page.locator('#project-table tbody tr a', { hasText: new RegExp(`^${VAULT_PROJECT}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      await expect(page.locator('h2').first()).toHaveText(VAULT_PROJECT);
      return new URL(page.url()).searchParams.get('id') as string;
    }
  }
  throw new Error(`Project ${VAULT_PROJECT} is not listed`);
}

const PROJECT_PAGE = /\/project\/view\?id=(\d+)/;

/**
 * Creates an open git project (no team) whose repository URL carries
 * credentials, through a real form post of the open (admin) page, and waits
 * until its sync has failed: the URL points at a port nothing listens on.
 * Returns its id. The credentials are a fixture, not a secret.
 */
async function createProjectWithUrlCredentials(admin: Page, name: string, url: string): Promise<string> {
  await admin.goto('/project/index');
  await forgePost(admin, '/project/create', {
    'Project[name]': name,
    'Project[scm_type]': 'git',
    'Project[scm_url]': url,
    'Project[scm_branch]': 'main',
  }, PROJECT_PAGE);
  const id = admin.url().match(PROJECT_PAGE)?.[1] ?? '';
  expect(id, 'the new project opens').toBeTruthy();
  // The sync output of a running sync reaches the page through its poll;
  // the page is checked once the sync has ended.
  await expect.poll(async () => {
    const answer = await admin.request.get(`/project/sync-status?id=${id}&since=0`);
    return answer.ok() ? (await answer.json()).is_syncing : true;
  }, { timeout: 60_000, message: 'the sync of the new project ends' }).toBe(false);
  return id;
}

async function deleteProject(admin: Page, id: string): Promise<void> {
  await admin.goto(`/project/view?id=${id}`);
  await forgePost(admin, `/project/delete?id=${id}`, {}, /\/project\/index/);
}

async function expectVaultCard(page: Page) {
  await expect(page.getByTestId('project-vault')).toBeVisible();
  await expect(page.getByTestId('project-vault-entry')).toHaveCount(VAULT_ENTRIES);
  await expect(page.getByTestId('project-vault-finding')).toHaveCount(4);
  const fits = page.getByTestId('project-vault-template');
  await expect(fits.filter({ hasText: 'e2e-vault-scan-dev-ok' })).toHaveAttribute('data-status', 'ok');
  await expect(fits.filter({ hasText: 'e2e-vault-scan-prod-wrong' })).toHaveAttribute('data-status', 'mismatch');
  await expect(fits.filter({ hasText: 'e2e-vault-scan-nopass' })).toHaveAttribute('data-status', 'missing_password');
  // Operators and viewers hold credential.view: the card names the vault password.
  await expect(fits.filter({ hasText: 'e2e-vault-scan-dev-ok' }).locator('td').nth(1)).toHaveText('e2e-vault-scan-devpass');
}

test.describe('Projects RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot see create button', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator(BTN_CREATE)).not.toBeVisible();
  });

  test('viewer gets 403 on project create', async ({ page }) => {
    await page.goto('/project/create');
    await expectForbidden(page);
  });

  test('operator can access project index', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator can create projects', async ({ page }) => {
    await page.goto('/project/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer cannot see edit/delete buttons on project view', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('a:has-text("Edit")')).not.toBeVisible();
    await expect(page.locator('button:has-text("Delete"), form[action*="/project/delete"] button')).not.toBeVisible();
  });

  test('viewer gets 403 on project update URL', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Project link has no numeric id');
      return;
    }
    await page.goto(`/project/update?id=${match[1]}`);
    await expectForbidden(page);
  });

  // Regression: the project form posted as JSON with an empty list as the
  // repository credential answered 500 for an operator too, who may create
  // projects: Yii's rules took the list for empty, the column stored it as
  // 0, and its foreign key refused that. The field's integer error is shown
  // now, and no project is created.
  test('operator gets the field error for a project posted as JSON with an empty list as the repository credential, and none is created', async ({ page }) => {
    const name = 'e2e-json-operator-credential-list-project';
    await page.goto('/project/create');

    const answer = await postJsonWithPageCsrf(page, '/project/create', gitProjectWithCredential(name, '[]'));

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(fieldError(page, 'project', 'scm_credential_id')).toHaveText(NOT_A_CREDENTIAL_ID);
    await page.goto('/project/index');
    await expect(page.locator('#project-table tbody'), 'no project was created').not.toContainText(name);
  });

  test('operator can see edit button on project view', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('a:has-text("Edit")').first()).toBeVisible({ timeout: 5_000 });
  });

  test('operator cannot delete projects (delete button hidden)', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('form[action*="/project/delete"] button')).not.toBeVisible();
  });

  test('operator sees the vault card and can rescan the project', async ({ page }) => {
    await openVaultProject(page);
    await expectVaultCard(page);

    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForURL(/#vault$/), page.getByTestId('project-vault-rescan').click()]);

    await expectFlash(page, 'success', 'Vault files rescanned.');
    await expectVaultCard(page);
  });

  test('viewer sees the vault card but no Rescan button', async ({ page }) => {
    await openVaultProject(page);

    await expectVaultCard(page);
    await expect(page.getByTestId('project-vault-source')).toHaveText('Ansilume only');
    await expect(page.getByTestId('project-vault-rescan')).toHaveCount(0);
  });

  test('viewer cannot rescan with a forged POST', async ({ page }) => {
    const id = await openVaultProject(page);

    // A real form post with a valid CSRF token: only the permission check may stop it.
    await Promise.all([
      page.waitForURL(/\/project-vault\/scan/),
      page.evaluate((projectId) => {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = `/project-vault/scan?id=${projectId}`;
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = (document.querySelector('meta[name="csrf-param"]') as HTMLMetaElement | null)?.content || '_csrf';
        csrf.value = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement).content;
        form.appendChild(csrf);
        document.body.appendChild(form);
        form.submit();
      }, id),
    ]);

    await expectForbidden(page);
  });

  // Regression: the project page showed the repository URL with the
  // credentials of its userinfo to everyone who may view the project, and
  // its sync output showed them in the clone command.
  test('viewer sees the repository URL without its credentials', async ({ page, browser }, testInfo) => {
    const suffix = `${Date.now()}`;
    const token = `e2e-scm-token-${suffix}`;
    const id = await asAdmin(browser, testInfo, (admin) => createProjectWithUrlCredentials(
      admin,
      `e2e-scm-url-${suffix}`,
      `https://e2e-user:${token}@127.0.0.1:1/org/e2e-repo.git`,
    ));
    try {
      await page.goto(`/project/view?id=${id}`);
      await expect(page.locator('h2').first()).toHaveText(`e2e-scm-url-${suffix}`);

      await expect(page.locator('#page-content dt:has-text("URL") + dd')).toHaveText('https://***@127.0.0.1:1/org/e2e-repo.git');
      await expect(page.locator('#sync-log-output')).toContainText('git clone');
      await expect(page.locator('#sync-log-output')).toContainText('https://***@127.0.0.1:1/org/e2e-repo.git');
      const html = await page.content();
      expect(html.split(token).length - 1, 'occurrences of the URL credentials in the page').toBe(0);
      expect(html.split('e2e-user:').length - 1, 'occurrences of the URL user in the page').toBe(0);

      // Regression: the page's poll of its sync output (/project/sync-status)
      // answered the rows and the error with the credentials.
      const polled = await page.request.get(`/project/sync-status?id=${id}&since=0`, { headers: { Accept: 'application/json' } });
      expect(polled.status()).toBe(200);
      const answer = (await polled.json()) as { logs: Array<{ content: string }>; last_sync_error: string | null };
      const answered = JSON.stringify(answer);
      expect(answered.split(token).length - 1, 'occurrences of the URL credentials in the poll').toBe(0);
      expect(answered.split('e2e-user:').length - 1, 'occurrences of the URL user in the poll').toBe(0);
      expect(answer.logs.map((row) => row.content).join('')).toContain('https://***@127.0.0.1:1/org/e2e-repo.git');
    } finally {
      await asAdmin(browser, testInfo, (admin) => deleteProject(admin, id));
    }
  });
});

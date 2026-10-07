import { test, expect, Page } from '@playwright/test';
import { deleteByRowText, expectFlash, submitForm } from '../../lib/helpers';

// Fixtures from commands/E2eVaultScanSeeder.php: a copy of
// tests/fixtures/vault/repo as a manual project in "Ansilume only" mode,
// scanned during the seed. The scan never decrypts: it finds encrypted files
// and inline !vault values, reads the repository's ansible.cfg and checks
// each job template's vault password against the vault HMACs.
const PROJECT = 'e2e-vault-scan-project';
const DEV_OK = 'e2e-vault-scan-dev-ok';
const PROD_WRONG = 'e2e-vault-scan-prod-wrong';
const NO_PASSWORD = 'e2e-vault-scan-nopass';
const DEV_VAULT = 'e2e-vault-scan-devpass';
const ENTRIES = [
  'group_vars/all.yml:3',
  'inventories/dev/group_vars/all/vault.yml',
  'inventories/prod/group_vars/all/vault.yml',
  'inventories/prod/host_vars/prod-web1.yml:2',
  'playbooks/deploy-vars.yml',
  'playbooks/group_vars/web.yml',
  'vars/secrets.yml',
];
// Plaintext of the fixture: the dummy vault passwords (also in the committed
// .vault_pass) and the encrypted values. None of it may reach the page.
const PLAINTEXT = ['ansilume-test-dummy', 'dummy-repo-secret', 'dummy-repo-app', 'dummy-dev-group', 'dummy-prod-group', 'dummy-prod-host'];

/** Opens the project page, paging through the list (newest first); returns the project id. */
async function openProject(page: Page, name: string): Promise<string> {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/project/index?page=${listPage}`);
    const link = page.locator('#project-table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      await expect(page.locator('h2').first()).toHaveText(name);
      return new URL(page.url()).searchParams.get('id') as string;
    }
  }
  throw new Error(`Project ${name} is not listed`);
}

function templateRow(page: Page, name: string) {
  return page.getByTestId('project-vault-template').filter({ has: page.locator('a', { hasText: new RegExp(`^${name}$`) }) });
}

async function expectFits(page: Page, fits: Record<string, string>) {
  for (const [name, status] of Object.entries(fits)) {
    await expect(templateRow(page, name)).toHaveAttribute('data-status', status);
  }
}

/** Clicks Rescan, accepts the confirmation and returns its text. */
async function rescan(page: Page): Promise<string> {
  let question = '';
  page.once('dialog', async (dialog) => {
    question = dialog.message();
    await dialog.accept();
  });
  await Promise.all([page.waitForURL(/#vault$/), page.getByTestId('project-vault-rescan').click()]);
  return question;
}

async function setPasswordSource(page: Page, projectId: string, label: string) {
  await page.goto(`/project/update?id=${projectId}`);
  await page.locator('#project-vault_password_source').selectOption({ label });
  await submitForm(page);
  await expectFlash(page, 'success', `Project "${PROJECT}" updated.`);
}

/** Id of the newest audit entry of the action, 0 when there is none. */
async function latestAuditId(page: Page, action: string): Promise<number> {
  await page.goto(`/audit-log/index?action=${action}`);
  const idLink = page.locator('table.table tbody tr td:first-child a').first();
  return await idLink.count() > 0 ? Number(await idLink.innerText()) : 0;
}

/** Opens the audit entry the action wrote after $previousId and checks who did it to which project. */
async function openNewAudit(page: Page, action: string, previousId: number, projectId: string) {
  expect(await latestAuditId(page, action), `a new ${action} audit entry`).toBeGreaterThan(previousId);
  const row = page.locator('table.table tbody tr').first();
  await expect(row).toContainText('e2e-admin');
  await expect(row).toContainText(action);
  await expect(row).toContainText(`project #${projectId}`);
  await row.locator('a').first().click();
}

// Fixtures from commands/E2eVaultEdgeSeeder.php: a project in "Ansilume and
// repository" mode whose ansible.cfg sets vault_id_match, with a vault labelled
// prod, a damaged vault and a vault whose file name is not UTF-8.
const EDGE_PROJECT = 'e2e-vault-edge-project';
const LABELLED = 'e2e-vault-edge-labelled';
const DAMAGED = 'e2e-vault-edge-damaged';
const ODD_NAME = 'e2e-vault-edge-oddname';

test.describe('Vault files of a project', () => {
  test('the vault card lists the encrypted files and values without their plaintext', async ({ page }) => {
    await openProject(page, PROJECT);

    const card = page.getByTestId('project-vault');
    await expect(card).toContainText('checked without decrypting');
    await expect(card).toContainText('7 encrypted files and inline values; vault IDs: prod.');
    await expect(page.getByTestId('project-vault-entry')).toHaveCount(ENTRIES.length);
    for (const location of ENTRIES) {
      await expect(page.locator('#project-vault-entries')).toContainText(location);
    }
    // Cells: file, kind, vault ID, problem.
    const prodFile = page.getByTestId('project-vault-entry').filter({ hasText: 'inventories/prod/group_vars/all/vault.yml' }).locator('td');
    await expect(prodFile).toHaveText(['inventories/prod/group_vars/all/vault.yml', 'encrypted file', 'prod', '']);
    const inline = page.getByTestId('project-vault-entry').filter({ hasText: 'inventories/prod/host_vars/prod-web1.yml:2' }).locator('td');
    await expect(inline).toHaveText(['inventories/prod/host_vars/prod-web1.yml:2', 'inline value host_secret', 'prod', '']);
    const devFile = page.getByTestId('project-vault-entry').filter({ hasText: 'inventories/dev/group_vars/all/vault.yml' }).locator('td');
    await expect(devFile).toHaveText(['inventories/dev/group_vars/all/vault.yml', 'encrypted file', 'default', '']);

    const html = await page.content();
    for (const plaintext of PLAINTEXT) {
      expect(html, `${plaintext} must never reach the page`).not.toContain(plaintext);
    }
  });

  test('the vault card reports the risky vault settings of the repository', async ({ page }) => {
    await openProject(page, PROJECT);

    const finding = (code: string) => page.locator(`[data-testid="project-vault-finding"][data-code="${code}"]`);
    await expect(page.getByTestId('project-vault-finding')).toHaveCount(4);
    await expect(finding('password_file_committed')).toHaveText('.vault_pass: A plaintext vault password is committed to the repository.');
    await expect(finding('ask_vault_pass')).toContainText('ansible.cfg: Jobs fail in \'Ansilume and repository\' mode');
    await expect(finding('vault_id_match')).toContainText('ansible.cfg: vault_id_match is on (any value, even False)');
    await expect(finding('encrypt_salt')).toContainText('ansible.cfg: vault_encrypt_salt is set');
  });

  test('the vault card shows whether each template\'s vault password opens its files', async ({ page }) => {
    await openProject(page, PROJECT);

    await expectFits(page, { [DEV_OK]: 'ok', [PROD_WRONG]: 'mismatch', [NO_PASSWORD]: 'missing_password' });
    await expect(templateRow(page, DEV_OK)).toContainText(DEV_VAULT);
    await expect(templateRow(page, DEV_OK)).toContainText('opens all');
    await expect(templateRow(page, PROD_WRONG)).toContainText('does not open all');
    await expect(templateRow(page, PROD_WRONG))
      .toContainText('inventories/prod/group_vars/all/vault.yml, inventories/prod/host_vars/prod-web1.yml:2');
    await expect(templateRow(page, NO_PASSWORD)).toContainText('no vault password');

    await templateRow(page, PROD_WRONG).locator('a').click();
    await expect(page.locator('h2').first()).toHaveText(PROD_WRONG);
    await expect(page.locator('[data-testid="template-warning"][data-code="vault_password_mismatch"]')).toBeVisible();
  });

  test('new projects use only Ansilume\'s vault password', async ({ page }) => {
    await page.goto('/project/create');

    await expect(page.locator('#project-vault_password_source')).toHaveValue('ansilume');
    await expect(page.locator('#project-vault_password_source option')).toHaveText(['Ansilume only', 'Ansilume and repository']);
  });

  test('switching to "Ansilume and repository" is shown, audited and re-checks the templates', async ({ page }) => {
    const audited = await latestAuditId(page, 'project.vault-source-changed');
    const id = await openProject(page, PROJECT);
    await expect(page.getByTestId('project-vault-source')).toHaveText('Ansilume only');
    await expect(page.getByTestId('project-vault')).toContainText('Vault passwords on runners: Ansilume only.');

    try {
      await setPasswordSource(page, id, 'Ansilume and repository');

      await expect(page.getByTestId('project-vault-source')).toHaveText('Ansilume and repository');
      await expect(page.getByTestId('project-vault'))
        .toContainText('Vault passwords on runners: Ansilume and repository. Runners also apply the vault settings of the repository\'s ansible.cfg.');
      // The repository's ansible.cfg names a password file Ansilume cannot check.
      await expectFits(page, { [DEV_OK]: 'ok', [PROD_WRONG]: 'repo_managed', [NO_PASSWORD]: 'repo_managed' });

      await openNewAudit(page, 'project.vault-source-changed', audited, id);
      await expect(page.locator('pre.job-log')).toContainText('"from": "ansilume"');
      await expect(page.locator('pre.job-log')).toContainText('"to": "repository"');
    } finally {
      await setPasswordSource(page, id, 'Ansilume only');
    }

    await expect(page.getByTestId('project-vault-source')).toHaveText('Ansilume only');
    await expectFits(page, { [DEV_OK]: 'ok', [PROD_WRONG]: 'mismatch', [NO_PASSWORD]: 'missing_password' });
  });

  test('Rescan scans the checkout again and is audited', async ({ page }) => {
    const audited = await latestAuditId(page, 'project.vault-scanned');
    const id = await openProject(page, PROJECT);

    expect(await rescan(page)).toBe(`Rescan the checkout of "${PROJECT}" for vault files?`);

    await expectFlash(page, 'success', 'Vault files rescanned.');
    await expect(page.getByTestId('project-vault-entry')).toHaveCount(ENTRIES.length);
    await expectFits(page, { [DEV_OK]: 'ok', [PROD_WRONG]: 'mismatch', [NO_PASSWORD]: 'missing_password' });
    await openNewAudit(page, 'project.vault-scanned', audited, id);
    await expect(page.locator('pre.job-log')).toContainText('"scanned": true');
  });

  // Manual projects never sync, so saving one scans it (regression: they
  // waited for a sync that never came and told the user to sync).
  test('a manual project is scanned when saved, and the card explains a missing directory', async ({ page }) => {
    const name = `e2e-vault-scan-tmp-${Date.now()}`;
    await page.goto('/project/create');
    await page.locator('#project-name').fill(name);
    await page.locator('#project-scm_type').selectOption('manual');
    await page.locator('#project-local_path').fill('/nonexistent/e2e-vault-scan');
    await submitForm(page);
    await expectFlash(page, 'success', `Project "${name}" created.`);
    const id = new URL(page.url()).searchParams.get('id') as string;
    const missing = 'The local path is not a directory the server can read: /nonexistent/e2e-vault-scan';

    try {
      await expect(page.getByTestId('project-vault-error')).toHaveText(missing);
      await expect(page.getByTestId('project-vault-entry')).toHaveCount(0);

      await rescan(page);
      await expect(page.locator('.alert-warning', { hasText: 'Vault files not scanned' }))
        .toContainText(`Vault files not scanned: ${missing}`);

      await page.goto(`/project/update?id=${id}`);
      await page.locator('#project-local_path').fill('/var/www/ansible/selftest');
      await submitForm(page);
      await expect(page.getByTestId('project-vault-error')).toHaveCount(0);
      await expect(page.getByTestId('project-vault-no-entries')).toHaveText('No encrypted files or inline vault values in the checkout.');
      await expect(page.getByTestId('project-vault-finding')).toHaveCount(0);

      await rescan(page);
      await expectFlash(page, 'success', 'Vault files rescanned.');
    } finally {
      await deleteByRowText(page, '/project/index', name);
    }
  });
});

test.describe('Vault check results beyond ok and mismatch', () => {
  test('the card shows vault_id_match, damaged vaults and file names it cannot check', async ({ page }) => {
    await openProject(page, EDGE_PROJECT);

    await expect(page.getByTestId('project-vault-source')).toHaveText('Ansilume and repository');
    await expect(page.locator('[data-testid="project-vault-finding"][data-code="vault_id_match"]')).toBeVisible();
    await expectFits(page, { [LABELLED]: 'mismatch', [DAMAGED]: 'damaged', [ODD_NAME]: 'incomplete' });

    // The prod password opens the prod vault, but vault_id_match keeps Ansible from trying it.
    await expect(templateRow(page, LABELLED)).toContainText('does not open all');
    await expect(templateRow(page, LABELLED)).toContainText('inventories/labelled/group_vars/all/vault.yml (vault_id_match)');
    await expect(templateRow(page, DAMAGED)).toContainText('damaged vault files');
    await expect(templateRow(page, DAMAGED)).toContainText('inventories/damaged/group_vars/all/vault.yml');
    await expect(templateRow(page, ODD_NAME)).toContainText('not fully checked');
    await expect(templateRow(page, ODD_NAME).getByTestId('project-vault-incomplete-reason')).toHaveText('file names that are not UTF-8:');

    const problem = (path: string) => page.getByTestId('project-vault-entry').filter({ hasText: path }).locator('td').nth(3);
    await expect(problem('inventories/damaged/group_vars/all/vault.yml'))
      .toHaveText('the vault body has spaces or tabs, e.g. trailing whitespace on a line');
    await expect(problem('inventories/oddname/group_vars/all/odd-'))
      .toHaveText('The file name is not valid UTF-8, so Ansilume cannot check this file.');
  });
});

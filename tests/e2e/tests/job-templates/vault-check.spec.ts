import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';

// Fixtures from commands/E2eVaultScanSeeder.php: a copy of
// tests/fixtures/vault/repo, scanned during the seed. Each template's vault
// password is checked against the vaults it probably loads, without
// decrypting. The result only warns: jobs still launch.
const DEV_OK = 'e2e-vault-scan-dev-ok';
const PROD_WRONG = 'e2e-vault-scan-prod-wrong';
const NO_PASSWORD = 'e2e-vault-scan-nopass';
const DEV_VAULT = 'e2e-vault-scan-devpass';
const PROD_VAULT = 'e2e-vault-scan-prodpass';
const MISMATCH = '[data-testid="template-warning"][data-code="vault_password_mismatch"]';
const MISSING = '[data-testid="template-warning"][data-code="vault_password_missing"]';
const DAMAGED_WARNING = '[data-testid="template-warning"][data-code="vault_file_damaged"]';
const INCOMPLETE_WARNING = '[data-testid="template-warning"][data-code="vault_check_incomplete"]';
// Fixtures from commands/E2eVaultEdgeSeeder.php ("Ansilume and repository"
// mode, vault_id_match in the repository's ansible.cfg).
const LABELLED = 'e2e-vault-edge-labelled';
const DAMAGED = 'e2e-vault-edge-damaged';
const ODD_NAME = 'e2e-vault-edge-oddname';
const ALL_TEMPLATES = [DEV_OK, PROD_WRONG, NO_PASSWORD, LABELLED, DAMAGED, ODD_NAME];
const PROD_WRONG_MESSAGE = `The vault password "${DEV_VAULT}" does not open 2 of the 4 encrypted files or values this template probably loads: `
  + 'inventories/prod/group_vars/all/vault.yml, inventories/prod/host_vars/prod-web1.yml:2. Jobs fail when Ansible needs one of them.';
const NO_PASSWORD_MESSAGE = 'This template has no vault password, but it probably loads 3 encrypted files or values. '
  + 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.';

/** Opens a template's page, paging through the list sorted by name; returns the template id. */
async function openTemplate(page: Page, name: string): Promise<string> {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/job-template/index?sort=name&page=${listPage}`);
    const link = page.locator('#template-table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      await expect(page.locator('h2').first()).toHaveText(name);
      return new URL(page.url()).searchParams.get('id') as string;
    }
  }
  throw new Error(`Job template ${name} is not listed`);
}

/** Sets which additional credentials are checked, saves and lands on the template page. */
async function saveAdditionalCredentials(page: Page, id: string, checked: Record<string, boolean>) {
  await page.goto(`/job-template/update?id=${id}`);
  for (const [name, on] of Object.entries(checked)) {
    await page.locator('.form-check', { has: page.locator('label', { hasText: name }) })
      .locator('input[type="checkbox"]').setChecked(on);
  }
  await submitForm(page);
  await expectFlash(page, 'success');
}

/** The summary banner on the template list: how many templates have the warning. */
async function summaryCount(page: Page, code: string, label: string, advice: string): Promise<number> {
  const summary = page.locator(`[data-testid="template-warning-summary"][data-code="${code}"]`);
  await expect(summary).toContainText(`job template(s) have ${label}. ${advice}`);
  const count = Number((await summary.innerText()).trim().split(' ')[0]);
  expect(count, `${code}: the banner starts with the number of templates`).toBeGreaterThan(0);
  return count;
}

test.describe('Vault check of job templates', () => {
  test('a template whose vault password does not open its prod vaults warns on its page and before launch', async ({ page }) => {
    const id = await openTemplate(page, PROD_WRONG);

    await expect(page.locator(MISMATCH)).toContainText(PROD_WRONG_MESSAGE);
    await expect(page.locator(MISSING)).toHaveCount(0);

    // A manual project never syncs: the remedy is a rescan.
    await expect(page.locator(MISMATCH)).toContainText('Check the password and the inventory; if the files changed, rescan the project.');

    await page.goto(`/job-template/launch?id=${id}`);
    await expect(page.locator(MISMATCH)).toContainText(PROD_WRONG_MESSAGE);
    // Only a warning: the launch stays possible.
    await expect(page.getByRole('button', { name: 'Launch Job' })).toBeEnabled();
  });

  test('a template without a vault password warns that it loads encrypted files', async ({ page }) => {
    const id = await openTemplate(page, NO_PASSWORD);

    await expect(page.locator(MISSING)).toHaveText(NO_PASSWORD_MESSAGE);
    await expect(page.locator(MISMATCH)).toHaveCount(0);

    await page.goto(`/job-template/launch?id=${id}`);
    await expect(page.locator(MISSING)).toHaveText(NO_PASSWORD_MESSAGE);
    await expect(page.getByRole('button', { name: 'Launch Job' })).toBeEnabled();
  });

  test('a template whose vault password opens all its vaults shows no vault warning', async ({ page }) => {
    const id = await openTemplate(page, DEV_OK);

    await expect(page.locator(`${MISMATCH}, ${MISSING}`)).toHaveCount(0);

    await page.goto(`/job-template/launch?id=${id}`);
    await expect(page.getByRole('button', { name: 'Launch Job' })).toBeVisible();
    await expect(page.locator(`${MISMATCH}, ${MISSING}`)).toHaveCount(0);
  });

  const fixing = 'They keep running, but need fixing.';
  const filters = [
    { code: 'vault_password_mismatch', label: 'a vault password that does not open their encrypted files', listed: PROD_WRONG, advice: fixing },
    { code: 'vault_password_missing', label: 'encrypted files but no vault password', listed: NO_PASSWORD, advice: fixing },
    { code: 'vault_file_damaged', label: 'encrypted files Ansible cannot read', listed: DAMAGED, advice: fixing },
    {
      code: 'vault_check_incomplete',
      label: 'a vault check that could not cover all encrypted files',
      listed: ODD_NAME,
      advice: 'Their jobs are not affected; the check is repeated at the next sync or rescan.',
    },
  ];
  for (const { code, label, listed, advice } of filters) {
    test(`the template list counts and shows the templates with ${code}`, async ({ page }) => {
      await page.goto('/job-template/index');
      const count = await summaryCount(page, code, label, advice);

      await page.locator(`[data-testid="template-warning-summary"][data-code="${code}"] a`, { hasText: 'Show them' }).click();

      await expect(page.getByTestId('template-warning-filter')).toContainText(`Showing job templates with ${label}.`);
      const names = page.locator('#template-table tbody tr td:nth-child(2)');
      // The count and the list come from the same filter.
      await expect(names).toHaveCount(Math.min(count, 20));
      await expect(names.filter({ hasText: new RegExp(`^${listed}$`) })).toHaveCount(1);
      // LABELLED also has vault_password_mismatch.
      for (const other of ALL_TEMPLATES.filter((name) => name !== listed && !(code === 'vault_password_mismatch' && name === LABELLED))) {
        await expect(names.filter({ hasText: new RegExp(`^${other}$`) })).toHaveCount(0);
      }
    });
  }

  test('with vault_id_match in the repository\'s ansible.cfg the warning says why the password is not tried', async ({ page }) => {
    await openTemplate(page, LABELLED);

    await expect(page.locator(MISMATCH)).toContainText(
      'The vault password "e2e-vault-scan-prodpass" does not open 1 of the 1 encrypted files or values this template probably loads: '
        + 'inventories/labelled/group_vars/all/vault.yml.'
    );
    await expect(page.locator(MISMATCH)).toContainText(
      'The repository\'s ansible.cfg sets vault_id_match, so Ansible tries this password only on vaults without a vault ID or with the ID "default".'
    );
  });

  test('a damaged vault the template loads is named on its page and before launch', async ({ page }) => {
    const id = await openTemplate(page, DAMAGED);
    const message = 'Ansible cannot read 1 of the encrypted files or values this template probably loads, whatever the password: '
      + 'inventories/damaged/group_vars/all/vault.yml. Jobs fail when Ansible loads one of them. '
      + 'The vault card of the project says what is wrong; encrypt them again.';

    await expect(page.locator(DAMAGED_WARNING)).toHaveText(message);
    await expect(page.locator(MISMATCH)).toHaveCount(0);

    await page.goto(`/job-template/launch?id=${id}`);
    await expect(page.locator(DAMAGED_WARNING)).toHaveText(message);
    await expect(page.getByRole('button', { name: 'Launch Job' })).toBeEnabled();
  });

  test('a check that cannot cover a file says why', async ({ page }) => {
    await openTemplate(page, ODD_NAME);

    await expect(page.locator(INCOMPLETE_WARNING)).toContainText(
      'The vault check of this template is incomplete: the names of 1 encrypted files it probably loads are not valid UTF-8, '
        + 'so Ansilume cannot check them: inventories/oddname/group_vars/all/odd-'
    );
    await expect(page.locator(`${MISMATCH}, ${MISSING}, ${DAMAGED_WARNING}`)).toHaveCount(0);
  });

  test('saving a template checks its vault password again: attaching one clears the warning', async ({ page }) => {
    const id = await openTemplate(page, NO_PASSWORD);
    await expect(page.locator(MISSING)).toBeVisible();

    try {
      await saveAdditionalCredentials(page, id, { [DEV_VAULT]: true });
      await expect(page.locator(`${MISMATCH}, ${MISSING}`)).toHaveCount(0);
    } finally {
      await saveAdditionalCredentials(page, id, { [DEV_VAULT]: false });
    }

    await expect(page.locator(MISSING)).toHaveText(NO_PASSWORD_MESSAGE);
  });

  test('saving a template checks its vault password again: the prod password does not open the dev vaults of the playbook', async ({ page }) => {
    const id = await openTemplate(page, PROD_WRONG);

    try {
      await saveAdditionalCredentials(page, id, { [DEV_VAULT]: false, [PROD_VAULT]: true });
      await expect(page.locator(MISMATCH)).toContainText(
        `The vault password "${PROD_VAULT}" does not open 2 of the 4 encrypted files or values this template probably loads: `
          + 'group_vars/all.yml:3, vars/secrets.yml.'
      );
    } finally {
      await saveAdditionalCredentials(page, id, { [PROD_VAULT]: false, [DEV_VAULT]: true });
    }

    await expect(page.locator(MISMATCH)).toContainText(PROD_WRONG_MESSAGE);
  });
});

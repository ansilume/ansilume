import { test, expect, Page } from '@playwright/test';
import { expectFlash, expectForbidden } from '../../lib/helpers';
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
});

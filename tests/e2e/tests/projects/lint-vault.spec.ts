import { test, expect, Page } from '@playwright/test';

// ansible-lint never gets a vault password on the server, so a playbook with
// vault-encrypted vars_files cannot be linted. That is reported neutrally, not
// as "issues found", and the repository's vault settings are never used.
const PLAINTEXT = 'E2E-VAULT-PLAINTEXT-MARKER';
const VERDICT = 'not lint-checked: vault-encrypted vars_files';

async function openByName(page: Page, index: string, name: string): Promise<boolean> {
  await page.goto(index);
  const link = page.locator('table.table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
  if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
    test.skip(true, `${name} is not seeded (ansible-vault missing?)`);
    return false;
  }
  await link.click();
  return true;
}

test.describe('Lint of vault-encrypted playbooks', () => {
  test('Run Lint on a vault project shows the neutral verdict', async ({ page }) => {
    test.setTimeout(120_000);
    if (!(await openByName(page, '/project/index', 'e2e-vault-project'))) return;

    await page.locator('button:has-text("Run Lint")').click();

    await expect(page.getByTestId('lint-badge')).toHaveText(VERDICT, { timeout: 90_000 });
    await expect(page.getByTestId('lint-vault-note')).toContainText('never decrypts vault content on the server');
    await expect(page.locator('body')).not.toContainText(PLAINTEXT);
  });

  test('a job template with vault vars_files shows the neutral verdict', async ({ page }) => {
    // Newest first: by name, templates created earlier in the run (clones)
    // push it off the first page.
    if (!(await openByName(page, '/job-template/index?sort=-id', 'e2e-vault-template'))) return;

    await expect(page.getByTestId('lint-badge')).toHaveText(VERDICT);
    await expect(page.getByTestId('lint-vault-note')).toBeVisible();
    await expect(page.locator('details summary', { hasText: 'Show ansible-lint output' })).toBeVisible();
  });
});

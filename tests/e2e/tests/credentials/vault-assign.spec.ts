import { test, expect, Page } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

// Fixtures from commands/E2eVaultAssignmentSeeder.php and E2eCredentialUsageSeeder.php
const VAULT_A = 'e2e-vault-assign-a';
const VAULT_B = 'e2e-vault-assign-b';
const BULK_NONE = 'e2e-vault-bulk-none';
const BULK_OTHER = 'e2e-vault-bulk-other';
const LEGACY = 'e2e-two-vaults-legacy';
const TOKEN = 'e2e-cred-usage-token';
const INCOMPLETE = 'e2e-cred-incomplete';

async function openCredential(page: Page, name: string) {
  await page.goto('/credential/index');
  await page.locator('#credential-table tbody tr', { hasText: name }).first()
    .locator('a', { hasText: new RegExp(`^${name}$`) }).click();
  await expect(page.locator('h2')).toHaveText(name);
}

function row(page: Page, template: string) {
  return page.locator('#vault-assign-templates tbody tr', { has: page.locator('label', { hasText: new RegExp(`^${template}$`) }) });
}

test.describe('Assigning a vault password to several job templates', () => {
  test('the picker shows each template\'s current vault password', async ({ page }) => {
    await openCredential(page, VAULT_A);
    await page.locator('#credential-assign-templates').click();

    await expect(row(page, BULK_NONE)).toHaveAttribute('data-current', 'none');
    await expect(row(page, BULK_OTHER)).toHaveAttribute('data-current', 'other');
    await expect(row(page, BULK_OTHER)).toContainText(`${VAULT_B}, will be replaced`);
    await expect(row(page, LEGACY)).toHaveAttribute('data-current', 'multiple');
  });

  test('assigning replaces another vault password and leaves exactly one', async ({ page }) => {
    await openCredential(page, VAULT_A);
    await page.locator('#credential-assign-templates').click();
    await row(page, BULK_NONE).locator('input[type="checkbox"]').check();
    await row(page, BULK_OTHER).locator('input[type="checkbox"]').check();

    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForNavigation(), page.locator('#vault-assign-submit').click()]);

    await expectFlash(page, 'success', 'assigned to 1 job template(s), replaced another vault password on 1');
    await expect(page.locator('h2')).toHaveText(VAULT_A);

    await page.goto('/job-template/index?sort=-id');
    await page.locator('#template-table tbody tr', { hasText: BULK_OTHER }).first()
      .locator('a', { hasText: new RegExp(`^${BULK_OTHER}$`) }).click();
    await expect(page.locator('#template-credentials')).toContainText(VAULT_A);
    await expect(page.locator('#template-credentials')).not.toContainText(VAULT_B);
  });

  test('submitting without a selection explains what is missing', async ({ page }) => {
    await openCredential(page, VAULT_A);
    await page.locator('#credential-assign-templates').click();

    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForNavigation(), page.locator('#vault-assign-submit').click()]);

    // Regression: the flash used to show the API's wording ("job_template_ids must be ...").
    await expectFlash(page, 'danger', 'Select at least one job template.');
  });

  test('only vault passwords with a usable secret offer the assignment', async ({ page }) => {
    await openCredential(page, TOKEN);
    await expect(page.locator('#credential-assign-templates')).toHaveCount(0);

    await openCredential(page, INCOMPLETE);
    await expect(page.locator('#credential-assign-templates')).toBeDisabled();
  });
});

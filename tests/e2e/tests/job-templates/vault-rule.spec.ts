import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';

// Fixtures from commands/E2eVaultAssignmentSeeder.php
const PROJECT = 'e2e-vault-assign-project';
const INVENTORY = 'e2e-vault-assign-inventory';
const LEGACY = 'e2e-two-vaults-legacy';
const REPAIR = 'e2e-two-vaults-fixme';
const VAULT_A = 'e2e-vault-assign-a';
const VAULT_B = 'e2e-vault-assign-b';
const VAULT_WARNING = '[data-testid="template-warning"][data-code="multiple_vault_credentials"]';

async function openTemplate(page: Page, name: string) {
  await page.goto('/job-template/index?sort=-id');
  await page.locator('#template-table tbody tr', { hasText: name }).first()
    .locator('a', { hasText: new RegExp(`^${name}$`) }).click();
  await expect(page.locator('h2').first()).toHaveText(name);
}

async function selectByLabel(page: Page, selector: string, label: string) {
  const option = page.locator(`${selector} option`, { hasText: label }).first();
  await page.locator(selector).selectOption((await option.getAttribute('value')) as string);
}

test.describe('One vault password per job template', () => {
  test('a template with two vault passwords says which one Ansible gets', async ({ page }) => {
    await openTemplate(page, LEGACY);

    const warning = page.locator(VAULT_WARNING);
    await expect(warning).toContainText(`"${VAULT_A}" takes precedence`);
    await expect(warning).toContainText(`"${VAULT_B}" is ignored`);
  });

  // Regression: the runner dropped the second vault password without a word.
  test('saving a template with two vault passwords is rejected until one is removed', async ({ page }) => {
    await openTemplate(page, REPAIR);
    await page.locator('#page-content a', { hasText: /^Edit$/ }).first().click();
    await expect(page.locator(VAULT_WARNING)).toBeVisible();

    await submitForm(page);
    await expect(page.locator('#credential-ids-error')).toContainText('Only one vault password can be attached');

    await page.locator('label.form-check-label', { hasText: VAULT_B }).click();
    await submitForm(page);
    await expectFlash(page, 'success');
    await expect(page.locator(VAULT_WARNING)).toHaveCount(0);
    await expect(page.locator('#template-credentials')).toContainText(VAULT_A);
    await expect(page.locator('#template-credentials')).not.toContainText(VAULT_B);
  });

  test('a new template cannot get two vault passwords', async ({ page }) => {
    await page.goto('/job-template/create');
    await page.locator('#jobtemplate-name').fill(`e2e-two-vaults-new-${Date.now()}`);
    await page.locator('#jobtemplate-playbook').fill('site.yml');
    await selectByLabel(page, '#jobtemplate-project_id', PROJECT);
    await selectByLabel(page, '#jobtemplate-inventory_id', INVENTORY);
    const runnerGroup = page.locator('#jobtemplate-runner_group_id option:not([value=""])').first();
    await page.locator('#jobtemplate-runner_group_id').selectOption((await runnerGroup.getAttribute('value')) as string);
    await selectByLabel(page, '#jobtemplate-credential_id', VAULT_A);
    await page.locator('label.form-check-label', { hasText: VAULT_B }).click();

    await submitForm(page);

    await expect(page.locator('#credential-ids-error')).toContainText(`"${VAULT_A}" and "${VAULT_B}" are both vault passwords`);
  });

  test('cloning a template with two vault passwords is refused with a readable reason', async ({ page }) => {
    await openTemplate(page, LEGACY);

    await page.getByRole('button', { name: 'Clone' }).click();
    await page.waitForLoadState('domcontentloaded');

    await expectFlash(page, 'danger', 'Clone failed: Only one vault password can be attached');
    await expect(page.locator('h2').first()).toHaveText(LEGACY);
  });

  test('the template list leads to the templates with two vault passwords', async ({ page }) => {
    await page.goto('/job-template/index');
    const summary = page.locator('[data-testid="template-warning-summary"][data-code="multiple_vault_credentials"]');
    await expect(summary).toContainText('more than one vault password');

    await summary.locator('a', { hasText: 'Show them' }).click();

    await expect(page.locator('[data-testid="template-warning-filter"]')).toBeVisible();
    await expect(page.locator('#template-table')).toContainText(LEGACY);
    await expect(page.locator('#template-table')).not.toContainText('e2e-vault-bulk-none');
  });
});

import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';

// Fixtures from commands/E2eCredentialUsageSeeder.php
const TEMPLATE = 'e2e-cred-usage-template';
const FORCE_TEMPLATE = 'e2e-cred-force-template';
const PRIMARY = 'e2e-cred-usage-primary';
const TOKEN = 'e2e-cred-usage-token';
const TOKEN_ENV_VAR = 'E2E_USAGE_TOKEN';
const TOKEN_SECRET = 'e2e-usage-token-secret-value';
const INCOMPLETE = 'e2e-cred-incomplete';
const UNDECRYPTABLE = 'e2e-cred-undecryptable';

async function openCredential(page: Page, name: string) {
  await page.goto('/credential/index');
  await page.locator('#credential-table tbody tr', { hasText: name }).first().locator('a', { hasText: name }).click();
  await expect(page.locator('h2')).toHaveText(name);
}

async function openTemplate(page: Page, name: string, action: 'view' | 'update' = 'view') {
  await page.goto('/job-template/index?sort=-id');
  await page.locator('table.table tbody tr', { hasText: name }).first().locator('a', { hasText: name }).click();
  await expect(page.locator('h2').first()).toContainText(name);
  if (action === 'update') {
    await page.locator('#page-content a', { hasText: /^Edit$/ }).first().click();
  }
}

test.describe('Credential usage and secret status', () => {
  test('a credential page lists the templates that use it', async ({ page }) => {
    await openCredential(page, PRIMARY);

    const templates = page.locator('#credential-usage-templates li', { hasText: TEMPLATE });
    await expect(templates).toHaveCount(1);
    await expect(templates.locator('.badge')).toHaveText('primary');
    await expect(page.locator('#credential-delete-form button')).toHaveText('Delete anyway');
  });

  test('a token credential shows its env var but never its secret', async ({ page }) => {
    await openCredential(page, TOKEN);

    await expect(page.locator('#credential-env-var')).toContainText(TOKEN_ENV_VAR);
    await expect(page.locator('#credential-usage-templates .badge')).toHaveText('additional');
    expect(await page.content()).not.toContain(TOKEN_SECRET);
  });

  test('an incomplete secret is flagged and the unused credential offers a plain delete', async ({ page }) => {
    await openCredential(page, INCOMPLETE);

    await expect(page.locator('#credential-secret-status')).toHaveAttribute('data-status', 'incomplete');
    await expect(page.locator('#credential-secret')).toContainText('Missing');
    await expect(page.locator('#credential-usage')).toContainText('Not used by any job template');
    await expect(page.locator('#credential-delete-form button')).toHaveText('Delete');
  });

  // Regression: the page of an SSH key credential whose secret could not be
  // decrypted failed with a 500.
  test('an undecryptable SSH key shows its status instead of failing', async ({ page }) => {
    await page.goto('/credential/index');
    const link = page.locator('#credential-table tbody tr', { hasText: UNDECRYPTABLE }).first().locator('a', { hasText: UNDECRYPTABLE });
    const href = await link.getAttribute('href');
    expect(href).toBeTruthy();

    const response = await page.goto(href as string);

    expect(response?.status()).toBe(200);
    await expect(page.locator('#credential-secret-status')).toHaveAttribute('data-status', 'undecryptable');
    await expect(page.locator('#credential-secret')).toContainText('Cannot be decrypted');
  });

  // Regression: deleting a credential in use silently detached it from its
  // templates. Now the page names the usage and the delete must be confirmed.
  test('a credential in use is deleted only after confirming, and is detached', async ({ page }) => {
    const name = `e2e-cred-force-${Date.now()}`;
    await page.goto('/credential/create');
    await page.locator('#credential-name').fill(name);
    await page.locator('#credential-type').selectOption('token');
    await page.locator('input[name="secrets[token]"]').fill('e2e-force-token');
    await submitForm(page);
    await expectFlash(page, 'success');

    await openTemplate(page, FORCE_TEMPLATE, 'update');
    await page.locator('label.form-check-label', { hasText: name }).click();
    await submitForm(page);
    await expectFlash(page, 'success');
    await expect(page.locator('#template-credentials')).toContainText(name);

    await openCredential(page, name);
    await expect(page.locator('#credential-usage-templates')).toContainText(FORCE_TEMPLATE);
    let confirmText = '';
    page.once('dialog', async (dialog) => {
      confirmText = dialog.message();
      await dialog.accept();
    });
    await Promise.all([page.waitForNavigation(), page.locator('#credential-delete-form button').click()]);

    expect(confirmText).toContain('is in use by 1 job template(s)');
    await expectFlash(page, 'success', 'detached');
    await openTemplate(page, FORCE_TEMPLATE);
    await expect(page.locator('#page-content')).not.toContainText(name);
  });

  test('dismissing the confirmation keeps the credential', async ({ page }) => {
    await openCredential(page, PRIMARY);
    page.once('dialog', (dialog) => dialog.dismiss());

    await page.locator('#credential-delete-form button').click();

    await expect(page.locator('h2')).toHaveText(PRIMARY);
    await openCredential(page, PRIMARY);
  });
});

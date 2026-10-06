import { test, expect } from '@playwright/test';
import { submitForm } from '../../lib/helpers';
import { FLASH_SUCCESS } from '../../lib/selectors';

// Fixture from commands/E2eCredentialUsageSeeder.php
const TOKEN = 'e2e-cred-usage-token';

test.describe('Credential secret validation', () => {
  // Regression: a credential without its secret was saved, and jobs ran
  // without the token, password or key.
  test('creating a credential without its secret shows an error', async ({ page }) => {
    await page.goto('/credential/create');
    await page.locator('#credential-name').fill(`e2e-cred-blank-${Date.now()}`);
    await page.locator('#credential-type').selectOption('token');

    await submitForm(page);

    await expect(page.locator('#credential-secrets-error')).toHaveText('Token is required for Token credentials.');
    await expect(page.locator(FLASH_SUCCESS)).toHaveCount(0);
  });

  // Regression: changing the type kept the old type's secret.
  test('changing the type needs the secret of the new type', async ({ page }) => {
    await page.goto('/credential/index');
    const row = page.locator('#credential-table tbody tr', { hasText: TOKEN }).first();
    await row.locator('a', { hasText: 'Edit' }).click();
    await expect(page.locator('#credential-type-change-notice')).toBeHidden();

    await page.locator('#credential-type').selectOption('vault');
    await expect(page.locator('#credential-type-change-notice')).toBeVisible();
    await submitForm(page);

    await expect(page.locator('#credential-secrets-error')).toHaveText('Changing the type to Vault Secret requires a new vault password.');
    await page.goto('/credential/index');
    await expect(page.locator('#credential-table tbody tr', { hasText: TOKEN }).first()).toContainText('Token');
  });
});

import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Credentials RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot create credentials', async ({ page }) => {
    await page.goto('/credential/create');
    await expectForbidden(page);
  });

  test('viewer can view index', async ({ page }) => {
    await page.goto('/credential/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('secrets are never visible in DOM', async ({ page }) => {
    await page.goto('/credential/index');
    const bodyHtml = await page.locator('body').innerHTML();
    expect(bodyHtml).not.toContain('e2e-dummy-token-value');
  });

  test('viewer gets 403 on credential create URL', async ({ page }) => {
    await page.goto('/credential/create');
    await expectForbidden(page);
  });

  test('operator can access credential create form', async ({ page }) => {
    await page.goto('/credential/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  // Fixtures from commands/E2eCredentialUsageSeeder.php. The e2e project
  // belongs to e2e-team, which only the operator is a member of.
  test('viewer outside the project team only sees how many templates use a credential', async ({ page }) => {
    await page.goto('/credential/index');
    await page.locator('#credential-table tbody tr', { hasText: 'e2e-cred-usage-primary' }).first()
      .locator('a', { hasText: 'e2e-cred-usage-primary' }).click();

    const templates = page.locator('#credential-usage-templates');
    await expect(templates).toContainText('+ 1 job template(s) in projects you cannot see');
    await expect(templates).not.toContainText('e2e-cred-usage-template');
    await expect(page.locator('#credential-delete-form')).toHaveCount(0);
  });

  test('operator can edit but not delete a credential in use', async ({ page }) => {
    await page.goto('/credential/index');
    await page.locator('#credential-table tbody tr', { hasText: 'e2e-cred-usage-primary' }).first()
      .locator('a', { hasText: 'e2e-cred-usage-primary' }).click();

    await expect(page.locator('#credential-usage-templates')).toContainText('e2e-cred-usage-template');
    await expect(page.locator('#page-content a', { hasText: /^Edit$/ })).toHaveCount(1);
    await expect(page.locator('#credential-delete-form')).toHaveCount(0);
  });

  test('secrets of seeded credentials never reach the credential page', async ({ page }) => {
    await page.goto('/credential/index');
    await page.locator('#credential-table tbody tr', { hasText: 'e2e-cred-usage-token' }).first()
      .locator('a', { hasText: 'e2e-cred-usage-token' }).click();

    expect(await page.content()).not.toContain('e2e-usage-token-secret-value');
  });

  // Fixtures from commands/E2eVaultAssignmentSeeder.php
  test('viewer cannot assign a vault password to job templates', async ({ page }) => {
    await page.goto('/credential/index');
    await page.locator('#credential-table tbody tr', { hasText: 'e2e-vault-assign-a' }).first()
      .locator('a', { hasText: 'e2e-vault-assign-a' }).click();
    await expect(page.locator('#credential-assign-templates')).toHaveCount(0);

    const id = new URL(page.url()).searchParams.get('id');
    await page.goto(`/credential-assignment/index?id=${id}`);
    await expectForbidden(page);
  });

  test('operator is only offered job templates they may change', async ({ page }) => {
    await page.goto('/credential/index');
    await page.locator('#credential-table tbody tr', { hasText: 'e2e-vault-assign-a' }).first()
      .locator('a', { hasText: 'e2e-vault-assign-a' }).click();
    await page.locator('#credential-assign-templates').click();

    const picker = page.locator('#vault-assign-templates');
    await expect(picker).toContainText('e2e-vault-bulk-none');
    // Another team's project, which the operator cannot even see.
    await expect(picker).not.toContainText('e2e-vault-bulk-beta');
    // A project the operator's team may only view (E2eTeamScopingSeeder): seeing is not changing.
    await expect(picker).not.toContainText('e2e-alpha-viewed-tmpl');
  });
});

import { test, expect } from '@playwright/test';

const LDAP_USERNAME = 'e2e-ldap-user';
const LOCAL_USERNAME = 'e2e-viewer';

test.describe('LDAP user edit form', () => {
  test('edit form for an LDAP user hides the password field and shows directory notice', async ({ page }) => {
    await page.goto('/user/index');
    await page.locator('table.table tbody tr', { hasText: LDAP_USERNAME })
      .locator('a').first().click();

    // Go through the Edit button on the view page.
    await page.locator('a:has-text("Edit")').first().click();
    await expect(page.locator('h2')).toContainText(/Edit:/i);

    // Password input must NOT render for LDAP-backed accounts.
    await expect(page.locator('#userform-password')).toHaveCount(0);

    // The directory alert explains why.
    await expect(page.locator('.alert-info')).toContainText(/managed by the directory/i);

    // Non-editable source display shows the LDAP badge.
    await expect(page.locator('.badge.text-bg-info')).toContainText(/LDAP/i);

    // DN / UID are rendered as read-only code blocks.
    await expect(page.locator('code', { hasText: 'uid=e2e-ldap-user,dc=e2e,dc=test' })).toBeVisible();
    await expect(page.locator('code', { hasText: 'guid-e2e-ldap' })).toBeVisible();
  });

  test('edit form for a local user still shows the password field', async ({ page }) => {
    await page.goto('/user/index');
    await page.locator('table.table tbody tr', { hasText: LOCAL_USERNAME })
      .locator('a').first().click();
    await page.locator('a:has-text("Edit")').first().click();

    await expect(page.locator('#userform-password')).toBeVisible();
    // And the "managed by the directory" alert must NOT leak onto local
    // edit forms — that would mislead an admin into not touching the password.
    await expect(page.locator('.alert-info:has-text("managed by the directory")')).toHaveCount(0);
  });
});

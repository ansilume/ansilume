import { test, expect } from '@playwright/test';

const LDAP_USERNAME = 'e2e-ldap-user';

test.describe('LDAP user view', () => {
  test('user list links the seeded LDAP user', async ({ page }) => {
    await page.goto('/user/index');
    const row = page.locator('table.table tbody tr', { hasText: LDAP_USERNAME });
    await expect(row).toBeVisible();
  });

  test('view page renders LDAP badge and directory metadata', async ({ page }) => {
    await page.goto('/user/index');
    const row = page.locator('table.table tbody tr', { hasText: LDAP_USERNAME });
    await row.locator('a').first().click();

    await expect(page.locator('body')).toContainText(LDAP_USERNAME);
    // The "Source" row must show the LDAP badge, never the "Local" badge,
    // so the operator can tell at a glance who lives in the directory.
    const sourceCell = page.locator('dt:has-text("Source") + dd');
    await expect(sourceCell).toContainText(/LDAP/i);
    await expect(sourceCell.locator('.badge')).toHaveClass(/text-bg-info/);

    // Directory-only fields must render for LDAP accounts.
    await expect(page.locator('dt:has-text("LDAP DN") + dd code')).toContainText('uid=e2e-ldap-user,dc=e2e,dc=test');
    await expect(page.locator('dt:has-text("Directory UID") + dd code')).toContainText('guid-e2e-ldap');
    await expect(page.locator('dt:has-text("Last synced") + dd')).not.toContainText(/Never/i);
  });

  test('view page for a local user shows the Local badge instead', async ({ page }) => {
    await page.goto('/user/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-viewer' });
    await row.locator('a').first().click();

    const sourceCell = page.locator('dt:has-text("Source") + dd');
    await expect(sourceCell).toContainText(/Local/i);
    await expect(sourceCell.locator('.badge')).toHaveClass(/text-bg-secondary/);
    // Local users must NOT leak directory-only labels into the detail card.
    await expect(page.locator('dt:has-text("LDAP DN")')).toHaveCount(0);
    await expect(page.locator('dt:has-text("Directory UID")')).toHaveCount(0);
  });
});

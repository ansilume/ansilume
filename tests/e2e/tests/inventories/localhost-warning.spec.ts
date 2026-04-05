import { test, expect } from '@playwright/test';
import { fillForm, submitForm, expectFlash } from '../../lib/helpers';

// Regression tests for the localhost-targeting warning banner.
//
// Background: running a playbook against an inventory that contains
// `localhost`, 127.0.0.1, ::1, or ansible_connection=local executes against
// the runner container itself, which mutates the runner image and can leak
// files into the host repository via the /var/www bind mount (for example,
// "Install nginx" drops /var/www/html/index.nginx-debian.html).
//
// The warning must appear on:
//   - the inventory detail page
//   - the job template detail page (when its inventory targets localhost)
//   - the launch page (same condition)
//
// and must NOT appear for inventories that only reference remote hosts.

const WARNING_LOCATOR = '[data-testid="localhost-warning"]';
const WARNING_TEXT = /targets the runner container itself/i;

test.describe('Localhost warning', () => {
  test('shown on the seeded e2e-inventory detail page (contains localhost)', async ({ page }) => {
    await page.goto('/inventory/index');
    await page.getByRole('link', { name: 'e2e-inventory', exact: true }).click();
    await expect(page.locator(WARNING_LOCATOR)).toBeVisible();
    await expect(page.locator(WARNING_LOCATOR)).toContainText(WARNING_TEXT);
  });

  test('shown on the job template detail page when inventory targets localhost', async ({ page }) => {
    await page.goto('/job-template/index');
    await page.getByRole('link', { name: 'e2e-template', exact: true }).click();
    await expect(page.locator(WARNING_LOCATOR)).toBeVisible();
    await expect(page.locator(WARNING_LOCATOR)).toContainText(WARNING_TEXT);
  });

  test('shown on the launch page when inventory targets localhost', async ({ page }) => {
    await page.goto('/job-template/index');
    await page.getByRole('link', { name: 'e2e-template', exact: true }).click();
    await page.getByRole('link', { name: /launch/i }).first().click();
    await expect(page).toHaveURL(/job-template\/launch/);
    await expect(page.locator(WARNING_LOCATOR)).toBeVisible();
  });

  test('NOT shown on an inventory that references only remote hosts', async ({ page }) => {
    const remoteName = `e2e-remote-only-${Date.now()}`;

    // Create a throw-away inventory with only remote hosts — no localhost,
    // no 127.0.0.1, no ansible_connection=local. The matcher must not flag it.
    await page.goto('/inventory/create');
    await fillForm(page, 'inventory', {
      name: remoteName,
      description: 'Remote-only inventory for localhost-warning regression',
    });
    const typeSelect = page.locator('#inventory-inventory_type');
    if (await typeSelect.isVisible()) {
      await typeSelect.selectOption('static');
    }
    await page.locator('#inventory-content').fill(
      "all:\n  hosts:\n    web01.example.com:\n      ansible_user: deploy\n    db01.example.com:\n      ansible_user: deploy\n",
    );
    await submitForm(page);
    await expectFlash(page, 'success');

    // We should now be on the detail page of the new inventory.
    await expect(page.locator('h2')).toContainText(remoteName);
    await expect(page.locator(WARNING_LOCATOR)).toHaveCount(0);

    // Clean up so the seeded state stays tidy across runs.
    const deleteForm = page.locator('form[onsubmit*="Delete"]');
    if (await deleteForm.count() > 0) {
      page.once('dialog', d => d.accept());
      await deleteForm.locator('button[type="submit"]').click();
    }
  });
});

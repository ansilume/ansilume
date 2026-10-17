import { test, expect, Page } from '@playwright/test';
import { postWithPageCsrf } from '../../lib/helpers';
import { asOperator, flash, ruleLabel } from '../../lib/approvals';
import {
  GATED_BADGE,
  HOST_INI_INVENTORY,
  HOST_RENAMED_INI_INVENTORY,
  HOST_RULE,
  INI_CONTENT,
  gatedBadge,
  inventoryDetails,
  inventoryId,
  inventoryListRow,
  inventoryUpdated,
} from '../../lib/inventories';

/**
 * A static inventory whose stored content is INI, as m000020 and m000035 seed
 * the selftest and demo inventories (HOST_INI_INVENTORY,
 * commands/E2eInventoryGateSeeder.php). Its edit form posts the content back
 * as stored, with a browser's CRLF line endings, and the YAML check runs only
 * for content that changes (models/Inventory.php): so an admin gives it an
 * approval rule and takes it away, and e2e-operator renames it and back,
 * while its content stays INI. Content that changes must be a YAML mapping.
 *
 * Regression (2.12.0 review, API-5): every save of such an inventory showed
 * the form again with "Invalid YAML: …", so it could not be given a rule or
 * renamed until an admin rewrote its content as YAML.
 */

/**
 * Saves the open inventory form with its Save Changes button, and expects
 * the save: a redirect to the inventory's page with the success flash for
 * `name`, not the form again with an error.
 */
async function saveForm(page: Page, name: string): Promise<void> {
  const [answer] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/inventory/update')),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
  expect(answer.status(), 'saved: a redirect, not the form again').toBe(302);
  await page.waitForURL(/\/inventory\/view\?id=\d+/);
  await expect(flash(page, 'success')).toHaveText(inventoryUpdated(name));
}

/**
 * Opens the edit form of inventory #`id`, which holds the INI content, as
 * stored, in its content field.
 */
async function openIniForm(page: Page, id: string): Promise<void> {
  await page.goto(`/inventory/update?id=${id}`);
  await expect(page.locator('#inventory-content')).toBeEnabled();
  expect(await page.locator('#inventory-content').inputValue(), 'the form holds the INI content').toBe(INI_CONTENT);
}

/**
 * Restores inventory #`id` as the seed leaves it, as e2e-admin: its name, no
 * rule, and the INI content byte for byte (the form posted it with CRLF line
 * breaks).
 */
async function restoreIniInventory(page: Page, id: string): Promise<void> {
  await page.goto(`/inventory/view?id=${id}`);
  const restored = await postWithPageCsrf(page, `/inventory/update?id=${id}`, {
    'Inventory[name]': HOST_INI_INVENTORY,
    'Inventory[content]': INI_CONTENT,
    'Inventory[approval_rule_id]': '',
  });
  expect(restored.status(), 'the inventory is restored').toBe(302);
}

test.describe('Inventory with INI content', () => {
  test('an admin gives it an approval rule on its form and takes it away, and its INI content stays', async ({ page }) => {
    const id = await inventoryId(page, HOST_INI_INVENTORY);
    await page.goto(`/inventory/view?id=${id}`);
    const before = await inventoryDetails(page);
    expect(before.content).toBe(INI_CONTENT);
    expect(before['Approval rule']).toBe('None');

    try {
      await openIniForm(page, id);
      await page.locator('#inventory-approval_rule_id').selectOption({ label: ruleLabel(HOST_RULE) });
      await saveForm(page, HOST_INI_INVENTORY);
      await expect(page.locator('#inventory-approval-rule a')).toHaveText(ruleLabel(HOST_RULE));
      expect((await inventoryDetails(page)).content, 'the content as stored').toBe(INI_CONTENT);
      await expect(gatedBadge(await inventoryListRow(page, HOST_INI_INVENTORY))).toHaveText(GATED_BADGE);

      await openIniForm(page, id);
      await page.locator('#inventory-approval_rule_id').selectOption('');
      await saveForm(page, HOST_INI_INVENTORY);
      await expect(page.locator('#inventory-approval-rule')).toHaveText('None');
    } finally {
      await restoreIniInventory(page, id);
    }
    await page.goto(`/inventory/view?id=${id}`);
    expect(await inventoryDetails(page)).toEqual(before);
    await expect(gatedBadge(await inventoryListRow(page, HOST_INI_INVENTORY))).toHaveCount(0);
  });

  test('e2e-operator renames it on its form and back, and its INI content stays', async ({ page, browser }, testInfo) => {
    const id = await inventoryId(page, HOST_INI_INVENTORY);
    await page.goto(`/inventory/view?id=${id}`);
    const before = await inventoryDetails(page);

    try {
      await asOperator(browser, testInfo, async (operator) => {
        for (const name of [HOST_RENAMED_INI_INVENTORY, HOST_INI_INVENTORY]) {
          await openIniForm(operator, id);
          await operator.locator('#inventory-name').fill(name);
          await saveForm(operator, name);
          await expect(operator.locator('#page-content h2').first()).toHaveText(name);
          expect((await inventoryDetails(operator)).content, 'the content as stored').toBe(INI_CONTENT);
        }
      });
    } finally {
      await restoreIniInventory(page, id);
    }
    await page.goto(`/inventory/view?id=${id}`);
    expect(await inventoryDetails(page)).toEqual(before);
  });

  test('content that changes must be a YAML mapping: other INI shows the form again with the error, and nothing is saved', async ({ page }) => {
    const id = await inventoryId(page, HOST_INI_INVENTORY);
    await page.goto(`/inventory/view?id=${id}`);
    const before = await inventoryDetails(page);

    try {
      await openIniForm(page, id);
      // The YAML editor syncs the textarea on its own edits only: set what the form posts.
      await page.locator('#inventory-content').evaluate((el) => {
        (el as HTMLTextAreaElement).value = '[web]\nweb1.example.com\n';
      });
      const [answer] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/inventory/update')),
        page.getByRole('button', { name: 'Save Changes' }).click(),
      ]);
      expect(answer.status(), 'the form again').toBe(200);
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('.field-inventory-content .help-block')).toHaveText(/^Invalid YAML: /);
    } finally {
      await restoreIniInventory(page, id);
    }
    await page.goto(`/inventory/view?id=${id}`);
    expect(await inventoryDetails(page)).toEqual(before);
  });
});

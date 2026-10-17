import { test, expect } from '@playwright/test';
import { flash } from '../../lib/approvals';
import {
  CARD_EMPTY,
  HOST_DELETED_ONLY_INVENTORY,
  HOST_IN_USE_INVENTORY,
  HOST_IN_USE_TEMPLATE,
  HOST_PROJECT,
  cardDeleted,
  deleteInUse,
  deleteOpenInventory,
  inventoryInUse,
  inventoryTemplates,
  openInventory,
} from '../../lib/inventories';

/**
 * Deleting an inventory that job templates use (services/InventoryWriteService.php
 * DELETE_IN_USE). job_template.inventory_id is NOT NULL with a restricting
 * foreign key, and a deleted job template keeps its inventory until its
 * project is deleted, so the delete is refused with a danger flash on the
 * inventory's page. Fixtures (commands/E2eInventoryGateSeeder.php):
 * e2e-host-in-use-inventory, which the live job template
 * e2e-host-in-use-template uses, and e2e-host-deleted-only-inventory, which
 * only a soft-deleted job template still refers to.
 *
 * regression: deleting an inventory that job templates use ended in a
 * server error: the delete failed on the foreign key (1451) with a 500
 * (InventoryControllerGateTest::testRegressionDeletingAnInventoryInUseAnswered500
 * is the same in PHPUnit).
 */
const IN_USE: { inventory: string; templates: number; deletedTemplates: number }[] = [
  { inventory: HOST_IN_USE_INVENTORY, templates: 1, deletedTemplates: 0 },
  { inventory: HOST_DELETED_ONLY_INVENTORY, templates: 0, deletedTemplates: 1 },
];

test.describe('Deleting an inventory in use', () => {
  for (const { inventory, templates, deletedTemplates } of IN_USE) {
    test(`regression: deleting an inventory that job templates use ended in a server error (${inventory})`, async ({ page }) => {
      const id = await openInventory(page, inventory);

      const deleted = await deleteOpenInventory(page);
      expect(deleted.confirm).toBe('Delete this inventory?');
      // The behaviour first: a redirect back to the inventory, not a server error.
      expect(deleted.status, 'the delete is answered with a redirect, not a server error').toBe(302);
      await expect(page).toHaveURL(new RegExp(`/inventory/view\\?id=${id}$`));
      await expect(flash(page, 'danger')).toHaveText(deleteInUse(inventoryInUse(inventory, templates, deletedTemplates)));
      await expect(flash(page, 'success')).toHaveCount(0);

      // The inventory stays, and its page shows what uses it.
      await expect(page.locator('#page-content h2').first()).toHaveText(inventory);
      if (templates > 0) {
        expect(await inventoryTemplates(page)).toEqual([[HOST_IN_USE_TEMPLATE, HOST_PROJECT, '—']]);
        await expect(page.locator('#inventory-job-templates-empty')).toHaveCount(0);
      } else {
        await expect(page.locator('#inventory-job-templates-list')).toHaveCount(0);
        await expect(page.locator('#inventory-job-templates-empty')).toHaveText(CARD_EMPTY);
      }
      if (deletedTemplates > 0) {
        await expect(page.locator('#inventory-job-templates-deleted')).toHaveText(cardDeleted(deletedTemplates));
      } else {
        await expect(page.locator('#inventory-job-templates-deleted')).toHaveCount(0);
      }

      await page.goto(`/inventory/view?id=${id}`);
      await expect(page.locator('#page-content h2').first()).toHaveText(inventory);
    });
  }
});

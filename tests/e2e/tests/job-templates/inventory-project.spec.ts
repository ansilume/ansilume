import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';

// Fixtures from commands/E2eInventoryProjectSeeder.php
const TARGET = 'e2e-xproj-target';
const SOURCE = 'e2e-xproj-source';
const FILE_INVENTORY = 'e2e-xproj-file-inv';
const DYNAMIC_INVENTORY = 'e2e-xproj-dynamic-inv';
const STATIC_INVENTORY = 'e2e-xproj-static-inv';
const LEGACY = 'e2e-xproj-legacy';
const INVENTORY_WARNING = '[data-testid="template-warning"][data-code="inventory_other_project"]';

async function selectByLabel(page: Page, selector: string, label: string) {
  const option = page.locator(`${selector} option`, { hasText: label }).first();
  await page.locator(selector).selectOption((await option.getAttribute('value')) as string);
}

async function fillNewTemplate(page: Page, name: string, inventory: string) {
  await page.goto('/job-template/create');
  await page.locator('#jobtemplate-name').fill(name);
  await page.locator('#jobtemplate-playbook').fill('site.yml');
  await selectByLabel(page, '#jobtemplate-project_id', TARGET);
  await selectByLabel(page, '#jobtemplate-inventory_id', inventory);
  const runnerGroup = page.locator('#jobtemplate-runner_group_id option:not([value=""])').first();
  await page.locator('#jobtemplate-runner_group_id').selectOption((await runnerGroup.getAttribute('value')) as string);
}

test.describe('File and dynamic inventories belong to the template\'s project', () => {
  test('the inventory list says which project a file inventory belongs to', async ({ page }) => {
    await page.goto('/job-template/create');

    await expect(page.locator('#jobtemplate-inventory_id option', { hasText: FILE_INVENTORY })).toHaveText(`${FILE_INVENTORY} (file, ${SOURCE})`);
    await expect(page.locator('#jobtemplate-inventory_id option', { hasText: STATIC_INVENTORY })).toHaveText(STATIC_INVENTORY);
  });

  for (const inventory of [FILE_INVENTORY, DYNAMIC_INVENTORY]) {
    // Regression: the runner looked for the file in the template's project, so
    // such a template silently used another inventory or none at all.
    test(`a new template cannot use ${inventory} of another project`, async ({ page }) => {
      await fillNewTemplate(page, `e2e-xproj-new-${Date.now()}`, inventory);

      await submitForm(page);

      await expect(page.locator('#jt-form'))
        .toContainText(`File and dynamic inventories must belong to the job template's project, but "${inventory}" belongs to another project`);
    });
  }

  test('a static inventory of another project is fine', async ({ page }) => {
    await fillNewTemplate(page, `e2e-xproj-static-${Date.now()}`, STATIC_INVENTORY);

    await submitForm(page);

    await expectFlash(page, 'success');
  });

  test('an older template with such an inventory shows a warning and is listed', async ({ page }) => {
    await page.goto('/job-template/index?warning=inventory_other_project');
    await expect(page.locator('[data-testid="template-warning-filter"]')).toContainText('an inventory of another project');
    await page.locator('#template-table tbody tr', { hasText: LEGACY }).first()
      .locator('a', { hasText: new RegExp(`^${LEGACY}$`) }).click();

    await expect(page.locator(INVENTORY_WARNING)).toContainText(`The inventory "${FILE_INVENTORY}" is a file or dynamic inventory of another project`);
  });
});

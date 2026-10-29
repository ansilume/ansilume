import { test, expect } from '@playwright/test';
import {
  expectFlash,
  fieldError,
  fillForm,
  submitForm,
  getTableRowCount,
  deleteByRowText,
  notAnInteger,
  postJsonWithPageCsrf,
  showAnswer,
} from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { INVENTORY_NAME_COLUMN, inventoryDetails, inventoryId } from '../../lib/inventories';

/** models/Inventory.php: the integer rule's message for a project that is no project ID. */
const NOT_A_PROJECT_ID = notAnInteger('Project Id');

/** models/Inventory.php: the integer rule's message for an approval rule that is no rule ID, under the rule select of admins. */
const NOT_A_RULE_ID = notAnInteger('Approval rule');

test.describe('Inventories CRUD', () => {
  test('index page lists inventories', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/inventor/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view inventory detail', async ({ page }) => {
    await page.goto('/inventory/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/inventor|type|content/i);
  });

  test('create static inventory', async ({ page }) => {
    await page.goto('/inventory/create');
    await fillForm(page, 'inventory', {
      name: 'e2e-crud-inventory',
      description: 'Created by E2E test',
    });
    const typeSelect = page.locator('#inventory-inventory_type');
    if (await typeSelect.isVisible()) {
      await typeSelect.selectOption('static');
    }
    // The textarea is replaced by a CodeMirror editor (yaml-editor.js)
    // and hidden. Set the form value directly on the textarea — the
    // editor's auto-indent makes keyboard.type unreliable for shaped YAML.
    await page.locator('textarea[data-yaml-editor]').evaluate((el, value) => {
      (el as HTMLTextAreaElement).value = value;
    }, 'all:\n  hosts:\n    testhost:\n      ansible_connection: local\n');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update inventory', async ({ page }) => {
    await page.goto('/inventory/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-inventory' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#inventory-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete inventory', async ({ page }) => {
    await deleteByRowText(page, '/inventory/index', 'e2e-crud-inventory');
    await expectFlash(page, 'success');
  });

  // Regression: the inventory form posted as JSON (the request's JSON parser
  // serves the web forms too; a script sends the CSRF token as a header)
  // with a project beyond the int range answered 500 on PHP 8.5: the access
  // check cast it with (int) ("The float 1.0E+30 is not representable as an
  // int, cast occurred"). Its error is shown now, before the access check
  // (TeamScopingTrait::refusesSubmittedProject()), and nothing is saved.
  test('a create posted as JSON with the project 1e30 shows the project error and creates nothing', async ({ page }) => {
    const name = 'e2e-json-project-inventory';
    await page.goto('/inventory/create');

    const answer = await postJsonWithPageCsrf(page, '/inventory/create', `{"Inventory": {"name": "${name}", "inventory_type": "dynamic", "source_path": "inventory.yml", "project_id": 1e30}}`);

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(fieldError(page, 'inventory', 'project_id')).toHaveText(NOT_A_PROJECT_ID);
    expect(rowsWhere(await indexRows(page, '/inventory/index'), INVENTORY_NAME_COLUMN, name), 'no inventory was created').toHaveLength(0);
  });

  test('an update posted as JSON with the project 1e30 shows the project error and changes nothing', async ({ page }) => {
    const id = await inventoryId(page, 'e2e-inventory');
    await page.goto(`/inventory/view?id=${id}`);
    const before = await inventoryDetails(page);
    await page.goto(`/inventory/update?id=${id}`);

    const answer = await postJsonWithPageCsrf(page, `/inventory/update?id=${id}`, '{"Inventory": {"name": "e2e-inventory-json-renamed", "project_id": 1e30}}');

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(fieldError(page, 'inventory', 'project_id')).toHaveText(NOT_A_PROJECT_ID);
    await page.goto(`/inventory/view?id=${id}`);
    await expect(page.locator('#page-content h2').first()).toHaveText('e2e-inventory');
    expect(await inventoryDetails(page), 'not even the name was saved').toEqual(before);
  });

  // Regression: an admin's inventory form posted as JSON with an empty list
  // as the approval rule answered 500: Yii's rules took the list for empty,
  // the column stored it as rule 0, and its foreign key refused that. The
  // rule select's integer error is shown now, before anything reads the
  // rule (InventoryController::refusesListValues()), and nothing is saved.
  test('a create posted as JSON with an empty list as the approval rule shows the rule\'s error, and creates nothing', async ({ page }) => {
    const name = 'e2e-json-rule-list-inventory';
    await page.goto('/inventory/create');

    const answer = await postJsonWithPageCsrf(page, '/inventory/create', `{"Inventory": {"name": "${name}", "inventory_type": "static", `
      + '"content": "all:\\n  hosts:\\n    localhost: {}\\n", "approval_rule_id": []}}');

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(fieldError(page, 'inventory', 'approval_rule_id')).toHaveText(NOT_A_RULE_ID);
    await expect(page.locator('#inventory-approval_rule_id'), 'no rule chosen').toHaveValue('');
    expect(rowsWhere(await indexRows(page, '/inventory/index'), INVENTORY_NAME_COLUMN, name), 'no inventory was created').toHaveLength(0);
  });

  // Regression: the same post on an update of e2e-inventory answered 500 too.
  test('an update posted as JSON with an empty list as the approval rule shows the rule\'s error, and changes nothing', async ({ page }) => {
    const id = await inventoryId(page, 'e2e-inventory');
    await page.goto(`/inventory/view?id=${id}`);
    const before = await inventoryDetails(page);
    await page.goto(`/inventory/update?id=${id}`);

    const answer = await postJsonWithPageCsrf(page, `/inventory/update?id=${id}`, '{"Inventory": {"name": "e2e-inventory-json-renamed", "approval_rule_id": []}}');

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(fieldError(page, 'inventory', 'approval_rule_id')).toHaveText(NOT_A_RULE_ID);
    await page.goto(`/inventory/view?id=${id}`);
    await expect(page.locator('#page-content h2').first()).toHaveText('e2e-inventory');
    expect(await inventoryDetails(page), 'not even the name was saved').toEqual(before);
  });
});

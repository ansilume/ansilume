import { Locator, Page, expect } from '@playwright/test';
import { regexLiteral } from './runs';
import { IndexRow, indexRows, rowsWhere } from './scoping';

/**
 * Fixtures of the specs for approval rules on inventories, restored in place
 * by every seed (commands/E2eInventoryGateSeeder.php). Each name mirrors the
 * seeder constant its comment names, with HOST_ in front of it (lib/approvals.ts
 * has a SOLO_RULE and a SOLO_TEMPLATE of its own);
 * tests/integration/commands/E2eInventoryGateSeederTest.php compares them.
 *
 * e2e-admin and e2e-operator approve on HOST_RULE and HOST_OTHER_RULE, so
 * whichever of the two launches, the other one decides. No name contains
 * "e2e-inventory", which yaml-editor.spec.ts looks for on the first page of
 * the inventory list. The seed restores these fixtures early, before
 * e2e-inventory: on a fresh seed they are older than most fixtures, so a list
 * sorted newest first may show them past its first page.
 */

/** E2eInventoryGateSeeder::RULE: e2e-admin and e2e-operator approve, one approval, rejected after 60 minutes; the rule of HOST_INVENTORY. */
export const HOST_RULE = 'e2e-host-gate-rule';

/** E2eInventoryGateSeeder::OTHER_RULE: e2e-admin and e2e-operator approve, one approval, no timeout; the own rule of HOST_CONFLICT_TEMPLATE. */
export const HOST_OTHER_RULE = 'e2e-host-other-rule';

/** E2eInventoryGateSeeder::SOLO_RULE: only e2e-operator approves; the rule of HOST_SOLO_INVENTORY. */
export const HOST_SOLO_RULE = 'e2e-host-solo-rule';

/** E2eInventoryGateSeeder::PROJECT: a manual project without a team, so every role sees its job templates. */
export const HOST_PROJECT = 'e2e-host-gate-project';

/** E2eInventoryGateSeeder::RUNNER_GROUP: no runner serves it, so an approved job stays queued. */
export const HOST_RUNNER_GROUP = 'e2e-host-gate-runner-group';

/** E2eInventoryGateSeeder::INVENTORY: static, without a project, gated by HOST_RULE; one host, e2e-host-gate-host (192.0.2.40). */
export const HOST_INVENTORY = 'e2e-gated-inventory';

/** E2eInventoryGateSeeder::RENAMED_INVENTORY: the name a spec gives HOST_INVENTORY before it renames it back. */
export const HOST_RENAMED_INVENTORY = 'e2e-gated-inventory-renamed';

/** E2eInventoryGateSeeder::TEMPLATE: in HOST_PROJECT on HOST_INVENTORY, without a rule of its own. */
export const HOST_TEMPLATE = 'e2e-host-gated-template';

/** E2eInventoryGateSeeder::RENAMED_TEMPLATE: the name a spec gives HOST_TEMPLATE before it renames it back. */
export const HOST_RENAMED_TEMPLATE = 'e2e-host-gated-template-renamed';

/** E2eInventoryGateSeeder::HIDDEN_TEMPLATE: on HOST_INVENTORY in HOST_HIDDEN_PROJECT; e2e-operator and e2e-viewer cannot see it, e2e-admin can. */
export const HOST_HIDDEN_TEMPLATE = 'e2e-host-hidden-template';

/** E2eInventoryGateSeeder::HIDDEN_PROJECT: only HOST_HIDDEN_TEAM has it. */
export const HOST_HIDDEN_PROJECT = 'e2e-host-hidden-project';

/** E2eInventoryGateSeeder::HIDDEN_TEAM: no members; HOST_HIDDEN_PROJECT is its only project. */
export const HOST_HIDDEN_TEAM = 'e2e-host-hidden-team';

/** E2eInventoryGateSeeder::SOLO_INVENTORY: gated by HOST_SOLO_RULE. */
export const HOST_SOLO_INVENTORY = 'e2e-host-solo-inventory';

/** E2eInventoryGateSeeder::SOLO_TEMPLATE: in HOST_PROJECT on HOST_SOLO_INVENTORY; a launch by e2e-operator is refused. */
export const HOST_SOLO_TEMPLATE = 'e2e-host-solo-template';

/** E2eInventoryGateSeeder::CONFLICT_INVENTORY: without a rule; its job template has a rule of its own. */
export const HOST_CONFLICT_INVENTORY = 'e2e-conflict-inventory';

/** E2eInventoryGateSeeder::CONFLICT_TEMPLATE: in HOST_PROJECT on HOST_CONFLICT_INVENTORY, with HOST_OTHER_RULE as its own rule. */
export const HOST_CONFLICT_TEMPLATE = 'e2e-conflict-template';

/** E2eInventoryGateSeeder::IN_USE_INVENTORY: without a rule; a live job template uses it. */
export const HOST_IN_USE_INVENTORY = 'e2e-host-in-use-inventory';

/** E2eInventoryGateSeeder::IN_USE_TEMPLATE: in HOST_PROJECT on HOST_IN_USE_INVENTORY. */
export const HOST_IN_USE_TEMPLATE = 'e2e-host-in-use-template';

/** E2eInventoryGateSeeder::DELETED_ONLY_INVENTORY: without a rule; only a soft-deleted job template uses it. */
export const HOST_DELETED_ONLY_INVENTORY = 'e2e-host-deleted-only-inventory';

/** E2eInventoryGateSeeder::DELETED_ONLY_TEMPLATE: in HOST_PROJECT on HOST_DELETED_ONLY_INVENTORY, soft-deleted on every seed. */
export const HOST_DELETED_ONLY_TEMPLATE = 'e2e-host-deleted-only-template';

/** E2eInventoryGateSeeder::ASSIGN_INVENTORY: without a rule on every seed; a spec gives it one and takes it away. */
export const HOST_ASSIGN_INVENTORY = 'e2e-host-assign-inventory';

/** E2eInventoryGateSeeder::ASSIGN_TEMPLATE: in HOST_PROJECT on HOST_ASSIGN_INVENTORY, without a rule of its own. */
export const HOST_ASSIGN_TEMPLATE = 'e2e-host-assign-template';

/**
 * E2eInventoryGateSeeder::INI_INVENTORY: static, without a rule, a project or
 * a job template on every seed; its content is INI_CONTENT, stored before the
 * YAML check, as in the seeded selftest and demo inventories.
 */
export const HOST_INI_INVENTORY = 'e2e-host-ini-inventory';

/** E2eInventoryGateSeeder::RENAMED_INI_INVENTORY: the name a spec gives HOST_INI_INVENTORY before it renames it back. */
export const HOST_RENAMED_INI_INVENTORY = 'e2e-host-ini-inventory-renamed';

/**
 * E2eInventoryGateSeeder::INI_CONTENT: the content of HOST_INI_INVENTORY, the
 * INI that m000020 seeds for the selftest inventory, which is no YAML
 * mapping: the form and the API keep it while the inventory's name,
 * description or approval rule change, and take changed content only as a
 * YAML mapping (models/Inventory.php).
 */
export const INI_CONTENT = '[local]\nlocalhost ansible_connection=local\n';

/** E2eInventoryGateSeeder::SCHEDULE: e2e-admin's disabled schedule of HOST_TEMPLATE, yearly on 1 January (UTC). */
export const HOST_SCHEDULE = 'e2e-host-gated-schedule';

/** E2eInventoryGateSeeder::WORKFLOW: HOST_STEP, then HOST_AFTER_STEP; e2e-operator finds its steps locked. */
export const HOST_WORKFLOW = 'e2e-host-gate-workflow';

/** E2eInventoryGateSeeder::STEP: the job step on HOST_TEMPLATE, at position 1. */
export const HOST_STEP = 'e2e-host-gate-step';

/** E2eInventoryGateSeeder::AFTER_STEP: the pause step, at position 2. */
export const HOST_AFTER_STEP = 'e2e-host-gate-after';

/*
 * The pages of an inventory with an approval rule (a gated inventory):
 * every launch of a job template that uses it waits for that rule, and only
 * admins (approval-rule.update) change its hosts (type, content, source
 * path, project), delete it, give a job template this inventory or change
 * what the job templates on it run (services/GatedInventoryLock.php). Name
 * and description stay open.
 */

/** GatedInventoryLock::NOTICE (services/GatedInventoryLock.php): on the page and edit form of a gated inventory, for users the lock applies to. */
export const INVENTORY_LOCK_NOTICE = 'This inventory has an approval rule: every launch of a job template that uses it waits '
  + 'for approval. Only admins (approval-rule.update) may change its hosts or delete it, give a job template '
  + 'this inventory, or change what the job templates that use it run. You may still change its name and '
  + 'description, and parse it.';

/** GatedInventoryLock::CHANGE_REFUSED: the 403 of a change of a gated inventory's hosts by a user the lock applies to. */
export const INVENTORY_CHANGE_REFUSED = 'This inventory has an approval rule: only admins may change its hosts '
  + '(approval-rule.update). You may change its name and description.';

/**
 * GatedInventoryLock::DELETE_REFUSED: the 403 of a delete of a gated
 * inventory by a user the lock applies to. Only a custom role that holds
 * inventory.delete without approval-rule.update meets it: the operator role
 * holds no inventory.delete.
 */
export const INVENTORY_DELETE_REFUSED = 'This inventory has an approval rule: only admins may delete it (approval-rule.update).';

/**
 * GatedInventoryLock::REPOSITORY_HOSTS_NOTE: a known limit, on the inventory
 * form (inside #field-project, which shows for file and dynamic inventories)
 * and on the page of a gated file or dynamic inventory.
 */
export const REPOSITORY_HOSTS_NOTE = 'The hosts of a file or dynamic inventory come from its project\'s '
  + 'repository when a runner claims the job, after the approval: the approval rule locks the source path and '
  + 'the project, not the file. Whoever may change that project (its repository URL or branch) or push to its '
  + 'repository changes these hosts without an admin.';

/** InventoryRuleAssignment::FORM_HINT (services/InventoryRuleAssignment.php): the hint of the inventory form's rule select (admins only). */
export const INVENTORY_RULE_FORM_HINT = 'Every launch of a job template that uses this inventory waits until the rule\'s '
  + 'approvers approve it. Only admins (approval-rule.update) may assign or remove a rule.';

/**
 * ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED: under the rule as text in the
 * inventory and job template forms of users who may not assign rules, and the
 * 403 of a request that changes a rule.
 */
export const ASSIGN_REFUSED = 'Only admins may assign or remove an approval rule: that needs approval-rule.update.';

/** Inventory::APPROVAL_RULE_NOT_FOUND (models/Inventory.php): the validation error of a rule ID that names no rule. */
export const APPROVAL_RULE_NOT_FOUND = 'The selected approval rule does not exist.';

/** Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED: the error of a dynamic inventory with a rule but without a source path. */
export const SOURCE_PATH_REQUIRED_WHEN_GATED = 'A dynamic inventory with an approval rule needs a source path: '
  + 'without one, jobs use the default inventory of Ansible or of the repository\'s ansible.cfg, '
  + 'which no rule gates.';

/** InventoryRuleAssignment::GATED_BADGE: the badge of a gated inventory in the Type cell of the inventory list. */
export const GATED_BADGE = 'needs approval';

/** InventoryRuleAssignment::GATED_TITLE: the title of that badge, with the rule's name. */
export function gatedTitle(rule: string): string {
  return `Every launch of a job template that uses this inventory waits for approval (rule "${rule}").`;
}

/**
 * SurveyDefinition::quote() (components/SurveyDefinition.php) of an ASCII
 * name: the name as a JSON string ("name"); quote() also escapes every
 * character outside printable ASCII, which no fixture name has.
 */
export function quoted(name: string): string {
  return JSON.stringify(name);
}

/**
 * InventoryRuleAssignment::RULES_DIFFER: the validation error of a rule that
 * job templates on the inventory cannot share, as an admin, who sees every
 * job template, gets it: the quoted names of the templates whose own rule is
 * another one, joined with ', '.
 */
export function inventoryRulesDiffer(templates: string[]): string {
  return `Job templates that use this inventory have another approval rule of their own: ${templates.map(quoted).join(', ')}. `
    + 'A launch waits for one rule only, so give them this rule or none first.';
}

/**
 * InventoryUsage::summary() (components/InventoryUsage.php), with
 * SUMMARY_IN_USE, PART_TEMPLATES and PART_DELETED: the inventory, quoted, and
 * the live and the deleted job templates that use it, counted.
 */
export function inventoryInUse(inventory: string, templates: number, deletedTemplates: number): string {
  const parts: string[] = [];
  if (templates > 0) {
    parts.push(`${templates} job template(s)`);
  }
  if (deletedTemplates > 0) {
    parts.push(`${deletedTemplates} deleted job template(s)`);
  }
  return `Inventory ${quoted(inventory)} is in use by ${parts.join(' and ')}.`;
}

/**
 * InventoryWriteService::DELETE_IN_USE (services/InventoryWriteService.php):
 * the danger flash (web) or 409 (API) of a delete of an inventory in use;
 * `summary` is inventoryInUse().
 */
export function deleteInUse(summary: string): string {
  return `${summary} Point its job templates at another inventory first. A deleted job template keeps `
    + 'its inventory for the history of its jobs until its project is deleted, so deleting the template does '
    + 'not free it.';
}

/** InventoryUsage::CARD_EMPTY: the Job templates card of an inventory no live job template uses. */
export const CARD_EMPTY = 'No job template uses this inventory.';

/** InventoryUsage::CARD_HIDDEN: the card's line for the job templates in projects the viewer cannot see. */
export function cardHidden(count: number): string {
  return `+ ${count} job template(s) in projects you cannot see`;
}

/** InventoryUsage::CARD_DELETED: the card's line for the soft-deleted job templates that still refer to the inventory. */
export function cardDeleted(count: number): string {
  return `${count} deleted job template(s) still refer to this inventory, for the history of their jobs.`;
}

/** InventoryController::FLASH_CREATED (controllers/InventoryController.php) */
export function inventoryCreated(name: string): string {
  return `Inventory "${name}" created.`;
}

/** InventoryController::FLASH_UPDATED */
export function inventoryUpdated(name: string): string {
  return `Inventory "${name}" updated.`;
}

/** InventoryController::FLASH_DELETED */
export function inventoryDeleted(name: string): string {
  return `Inventory "${name}" deleted.`;
}

/** Columns of /inventory/index (views/inventory/index.php): #, Name, Type, Project, Created by, and the actions. */
export const INVENTORY_NAME_COLUMN = 1;
export const INVENTORY_TYPE_COLUMN = 2;

/**
 * The row of the inventory `name` in the inventory list, every page of it
 * read (/inventory/index?page=N, 20 per page, newest first): fixtures seeded
 * early are past the first page.
 */
export async function inventoryRow(page: Page, name: string): Promise<IndexRow> {
  const rows = rowsWhere(await indexRows(page, '/inventory/index'), INVENTORY_NAME_COLUMN, name);
  expect(rows, `exactly one inventory named ${name}`).toHaveLength(1);
  return rows[0];
}

/** The ID of the inventory `name`, from the inventory list. */
export async function inventoryId(page: Page, name: string): Promise<string> {
  return (await inventoryRow(page, name)).id;
}

/** Opens the page of the inventory `name`, found in the inventory list; its ID. */
export async function openInventory(page: Page, name: string): Promise<string> {
  const id = await inventoryId(page, name);
  await page.goto(`/inventory/view?id=${id}`);
  await expect(page.locator('#page-content h2').first()).toHaveText(name);
  return id;
}

/**
 * Opens the page of the inventory list (/inventory/index?page=N) that lists
 * the inventory `name`, and returns its row there. Yii answers a page number
 * past the end with the last page, so the search stops when a page repeats
 * the previous one.
 */
export async function inventoryListRow(page: Page, name: string): Promise<Locator> {
  let previousFirstId = '';
  for (let listPage = 1; listPage <= 30; listPage++) {
    await page.goto(`/inventory/index?page=${listPage}`);
    const rows = page.locator('#inventory-table tbody tr');
    const row = rows.filter({
      has: page.locator(`td:nth-child(${INVENTORY_NAME_COLUMN + 1}) > a`, { hasText: new RegExp(`^${regexLiteral(name)}$`) }),
    });
    if (await row.count() > 0) {
      await expect(row, `one row of ${name}`).toHaveCount(1);
      return row;
    }
    const firstId = ((await rows.first().locator('td').first().textContent()) ?? '').trim();
    if (firstId === '' || firstId === previousFirstId) {
      break;
    }
    previousFirstId = firstId;
  }
  throw new Error(`Inventory ${name} is not listed`);
}

/** The badge of a gated inventory in the Type cell of `row`, a row of the inventory list. */
export function gatedBadge(row: Locator): Locator {
  return row.locator('td').nth(INVENTORY_TYPE_COLUMN).locator('[data-inventory-gated-badge]');
}

/** The notice of a gated inventory, on its page and its edit form (#inventory-locked-notice). */
export function inventoryLockedNotice(page: Page): Locator {
  return page.locator('#page-content #inventory-locked-notice');
}

/** The rows of the Job templates card of the open inventory page: tr[data-job-template-id]. */
export function inventoryTemplateRows(page: Page): Locator {
  return page.locator('#inventory-job-templates-list tbody tr[data-job-template-id]');
}

/**
 * The rows of the Job templates card of the open inventory page as
 * [job template, project, own approval rule], in the card's order.
 */
export async function inventoryTemplates(page: Page): Promise<string[][]> {
  return inventoryTemplateRows(page).evaluateAll((rows) => rows.map(
    (row) => Array.from(row.querySelectorAll('td')).map((cell) => (cell.textContent ?? '').replace(/\s+/g, ' ').trim()),
  ));
}

/** The inputs of the inventory form that stay open on a gated inventory: its name and description. */
export const OPEN_INVENTORY_INPUTS = ['#inventory-name', '#inventory-description'];

/**
 * The inputs of the inventory form for where its hosts come from
 * (fieldset#inventory-host-fields): on a gated inventory, disabled for users
 * the lock applies to, so the form does not post them.
 */
export const LOCKED_INVENTORY_INPUTS = ['#inventory-type', '#inventory-content', '#inventory-project_id', '#inventory-source_path'];

/**
 * The open inventory form is the one of a gated inventory for a user the
 * lock applies to: the notice, the name and description open, the host
 * inputs disabled, and the content a plain field without the YAML editor,
 * which would take edits that are not posted.
 */
export async function expectLockedInventoryForm(page: Page): Promise<void> {
  await expect(inventoryLockedNotice(page)).toHaveText(INVENTORY_LOCK_NOTICE);
  await expect(inventoryLockedNotice(page)).toHaveClass(/(^|\s)alert-info(\s|$)/);
  for (const selector of OPEN_INVENTORY_INPUTS) {
    await expect(page.locator(selector), selector).toBeEnabled();
  }
  for (const selector of LOCKED_INVENTORY_INPUTS) {
    await expect(page.locator(selector), selector).toBeDisabled();
  }
  await expect(page.locator('#inventory-host-fields')).toHaveAttribute('disabled', /.*/);
  await expect(page.locator('textarea[data-yaml-editor]')).toHaveCount(0);
  await expect(page.locator('.yaml-editor')).toHaveCount(0);
}

/**
 * The names of the fields the open inventory form would post, in form
 * order, without the CSRF token: FormData leaves out disabled fields.
 */
export async function postedInventoryFields(page: Page): Promise<string[]> {
  return page.locator('#inventory-form').evaluate((form) => {
    const csrfParam = document.querySelector('meta[name="csrf-param"]')?.getAttribute('content') || '_csrf';
    const names: string[] = [];
    new FormData(form as HTMLFormElement).forEach((_value, key) => {
      if (key !== csrfParam) {
        names.push(key);
      }
    });
    return names;
  });
}

/**
 * What the open inventory page says about the inventory: the Details card's
 * rows by label, and its content, for comparing before and after a refused
 * change.
 */
export async function inventoryDetails(page: Page): Promise<Record<string, string>> {
  const details = await page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Details$/ }) })
    .locator('dl').evaluate((dl) => {
      const result: Record<string, string> = {};
      dl.querySelectorAll('dt').forEach((dt) => {
        const dd = dt.nextElementSibling;
        result[(dt.textContent ?? '').trim()] = (dd?.textContent ?? '').replace(/\s+/g, ' ').trim();
      });
      return result;
    });
  const content = page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Content$/ }) }).locator('pre');
  details.content = (await content.count()) > 0 ? ((await content.textContent()) ?? '') : '';
  return details;
}

/**
 * Deletes the inventory whose page is open through its Delete button,
 * accepting its confirm dialog: the status of the delete's answer (a
 * redirect, 302, unless the server failed) and the text of the dialog. The
 * page the answer leads to is open then: the inventory list after a delete,
 * the inventory's page when it was refused.
 */
export async function deleteOpenInventory(page: Page): Promise<{ status: number; confirm: string }> {
  let confirm = '';
  page.once('dialog', (dialog) => {
    confirm = dialog.message();
    void dialog.accept();
  });
  const [answer] = await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/inventory/delete')),
    page.waitForNavigation(),
    page.locator('#page-content form[action*="/inventory/delete"] button[type="submit"]').click(),
  ]);
  return { status: answer.status(), confirm };
}

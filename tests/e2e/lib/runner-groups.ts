import { Locator, Page, expect } from '@playwright/test';
import { postWithPageCsrf } from './helpers';
import { idWhere, indexRows, rowsWhere } from './scoping';

/**
 * Helpers for the runner group pages (views/runner-group/). Fixtures:
 * commands/E2eRunnerGroupGuardSeeder.php.
 */

/** A runner group without runners, which the job template GROUP_TEMPLATE uses. */
export const GROUP_WITH_TEMPLATE = 'e2e-group-with-template';
export const GROUP_TEMPLATE = 'e2e-group-template';

/** A runner group without job templates, which the runner GROUP_RUNNER (never online) belongs to. */
export const GROUP_WITH_RUNNER = 'e2e-group-with-runner';
export const GROUP_RUNNER = 'e2e-group-runner';

/**
 * The confirm dialog of Delete on the runner group list (views/runner-group/index.php).
 * It asked 'Delete this runner group and all its runners?', but a group that
 * runners belong to is not deleted (RunnerGroupDeletionService).
 */
export const GROUP_DELETE_CONFIRM = 'Delete this runner group?';

/** services/RunnerGroupDeletionService.php TEMPLATES_REFUSED, the API's wording too. */
export function templatesRefused(group: string, templates: number): string {
  return `Cannot delete "${group}": ${templates} job template(s) reference this group.`;
}

/** services/RunnerGroupDeletionService.php RUNNERS_REFUSED, the API's wording too. */
export function runnersRefused(group: string, runners: number): string {
  return `Cannot delete "${group}": ${runners} runner(s) still belong to this group.`;
}

/** The row of the runner group `name` on the open runner group list (columns: Name, Description, Templates, Runners, actions). */
export function groupRow(page: Page, name: string): Locator {
  return page.locator('#page-content table.table tbody tr').filter({
    has: page.locator('td:first-child a', { hasText: new RegExp(`^${name}$`) }),
  });
}

/** The id of the runner group `name`, from the runner group list; needs runner-group.view. */
export async function groupId(page: Page, name: string): Promise<string> {
  const id = idWhere(await indexRows(page, '/runner-group/index'), 0, name);
  expect(id, `the id of runner group ${name}`).not.toBe('');
  return id;
}

/**
 * Deletes every runner group named `name`, its runners first, through the
 * delete forms of the runner group pages (the page's CSRF token, no confirm
 * dialog): the cleanup after a spec that stopped half-way. Needs
 * runner-group.update and runner-group.delete. Loads the list again after
 * each group, which uses up the flashes of these deletes, so the next spec
 * of the same session does not find them on its first page.
 */
export async function removeGroupsNamed(page: Page, name: string): Promise<void> {
  for (const group of rowsWhere(await indexRows(page, '/runner-group/index'), 0, name)) {
    await page.goto(`/runner-group/view?id=${group.id}`);
    const runnerDeletes = await page.locator('#page-content form[action*="/runner/delete"]')
      .evaluateAll((forms) => forms.map((form) => form.getAttribute('action') ?? ''));
    for (const action of runnerDeletes) {
      expect((await postWithPageCsrf(page, action)).status(), 'a runner of the group is deleted').toBe(302);
    }
    expect((await postWithPageCsrf(page, `/runner-group/delete?id=${group.id}`)).status(), 'the group is deleted').toBe(302);
    await page.goto('/runner-group/index');
  }
}

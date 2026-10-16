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
 * controllers/RunnerGroupController.php RUNNER_MANAGEMENT_ADMIN_ONLY: the note
 * of the runner group page, where the Add Runner form would be, for users
 * without runner-group.update (operators and viewers).
 */
export const RUNNER_MANAGEMENT_ADMIN_ONLY = 'Only admins register, move and delete runners and regenerate their tokens: that needs runner-group.update.';

/**
 * controllers/RunnerGroupController.php RUNNER_TOKENS_NOTE: the note of the
 * runner group list (data-testid runner-tokens-note). A runner group has no
 * token: each runner has one of its own.
 */
export const RUNNER_TOKENS_NOTE = 'Each runner authenticates with a token of its own: an admin hands it out with Add Runner on the runner group\'s page, or the runner registers itself with the bootstrap secret.';

/**
 * controllers/RunnerGroupController.php NO_RUNNERS and NO_RUNNERS_ADD_ONE: the
 * page of a group without runners (data-testid runner-group-empty), without
 * and with the Add Runner form above it (runner-group.update).
 */
export const NO_RUNNERS = 'No runners yet.';
export const NO_RUNNERS_ADD_ONE = 'No runners yet. Add one above.';

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
 * runner-group.update and runner-group.delete, which only admins hold
 * (m000087_000000_restrict_runner_management took runner-group.update from
 * the operator role): call it with an admin's page. Loads the list again after
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

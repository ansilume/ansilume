import { test, expect, Page } from '@playwright/test';
import { expectForbidden, postWithPageCsrf } from '../../lib/helpers';
import {
  GROUP_WITH_TEMPLATE,
  NO_RUNNERS,
  RUNNER_MANAGEMENT_ADMIN_ONLY,
  RUNNER_TOKENS_NOTE,
  groupId,
  groupRow,
} from '../../lib/runner-groups';
import { asAdmin } from '../../lib/scoping';
import { RUNNER_TOKEN_CARD, runnerRow } from '../../lib/tokens';

/** Fixture from commands/E2eController.php seedSecondRunnerGroup(), with the runners of E2eRunnerRegistrationSeeder. */
const SECOND_GROUP = 'e2e-runner-group-2';

/** A runner of SECOND_GROUP (commands/E2eRunnerRegistrationSeeder.php PLAINTEXT_RUNNER); no runner process uses its token. */
const FIXTURE_RUNNER = 'e2e-plaintext-runner';

/** Opens the page of the runner group `name` from the runner group list; its id. */
async function openGroup(page: Page, name: string): Promise<string> {
  const id = await groupId(page, name);
  await page.goto(`/runner-group/view?id=${id}`);
  await expect(page.locator('#page-content h2').first()).toHaveText(name);
  return id;
}

/**
 * Only admins hold runner-group.delete: the list offers no Delete, and a
 * delete posted with the page's CSRF token answers 403. The group is one in
 * use (commands/E2eRunnerGroupGuardSeeder.php), so a delete that got past the
 * permission check would still be refused, not carried out.
 */
async function expectDeleteForbidden(page: Page): Promise<void> {
  const id = await groupId(page, GROUP_WITH_TEMPLATE);
  await page.goto('/runner-group/index');
  await expect(groupRow(page, GROUP_WITH_TEMPLATE)).toHaveCount(1);
  await expect(page.locator('#page-content form[action*="/runner-group/delete"]')).toHaveCount(0);

  const response = await postWithPageCsrf(page, `/runner-group/delete?id=${id}`);

  expect(response.status()).toBe(403);
  await page.goto('/runner-group/index');
  await expect(groupRow(page, GROUP_WITH_TEMPLATE)).toHaveCount(1);
}

/**
 * Only admins hold runner-group.create and runner-group.update (migration
 * m000087_000000_restrict_runner_management): the runner group list offers
 * no New Group and no Edit, and the create page is forbidden.
 */
async function expectNoGroupManagementOnTheList(page: Page): Promise<void> {
  await page.goto('/runner-group/index');
  await expect(groupRow(page, SECOND_GROUP)).toHaveCount(1);
  await expect(page.locator('#page-content a[href*="/runner-group/create"]'), 'no New Group').toHaveCount(0);
  await expect(page.locator('#page-content a[href*="/runner-group/update"]'), 'no Edit').toHaveCount(0);

  await page.goto('/runner-group/create');
  await expectForbidden(page);
}

/**
 * The runner group page shows the group's runners, and where the Add Runner
 * form would be the note that only admins manage runners: no Add Runner
 * form, no Regen Token, Move or Delete, no Edit Group.
 */
async function expectTheNoteInsteadOfRunnerControls(page: Page): Promise<void> {
  await openGroup(page, SECOND_GROUP);
  await expect(runnerRow(page, FIXTURE_RUNNER)).toHaveCount(1);
  await expect(page.getByTestId('runner-management-admin-only')).toHaveText(RUNNER_MANAGEMENT_ADMIN_ONLY);
  await expect(page.locator('#page-content form[action*="/runner/"]'), 'no Add Runner, Regen Token, Move or Delete form').toHaveCount(0);
  for (const name of ['Create & Get Token', 'Regen Token', 'Move', 'Delete']) {
    await expect(page.locator('#page-content').getByRole('button', { name, exact: true }), name).toHaveCount(0);
  }
  await expect(page.locator('#page-content').getByRole('link', { name: 'Edit Group' })).toHaveCount(0);
}

/**
 * The runner group list says how runners get their tokens: a runner group
 * has no token (it said "using the group token"). The page of a group
 * without runners (GROUP_WITH_TEMPLATE) does not point at the Add Runner
 * form, which users without runner-group.update do not have.
 */
async function expectTheTokenNoteAndAnEmptyGroupWithoutTheForm(page: Page): Promise<void> {
  await page.goto('/runner-group/index');
  await expect(page.getByTestId('runner-tokens-note')).toHaveText(RUNNER_TOKENS_NOTE);
  await expect(page.locator('#page-content')).not.toContainText('group token');

  await openGroup(page, GROUP_WITH_TEMPLATE);
  await expect(page.getByTestId('runner-group-empty')).toHaveText(NO_RUNNERS);
  await expect(page.locator('#page-content form[action*="/runner/create"]'), 'no Add Runner form').toHaveCount(0);
}

test.describe('Runner Groups RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view index', async ({ page }) => {
    await page.goto('/runner-group/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer sees re-registration notices but no runner actions', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    const row = page.locator('table.table tbody tr', { hasText: 'e2e-reregistered-runner' });
    await expect(row.getByTestId('runner-reregistered-count')).toHaveText('2× in 24 h');
    await expect(row.locator('button:has-text("Regen Token"), button:has-text("Delete")')).toHaveCount(0);
  });

  test('viewer sees that only admins manage runners', async ({ page }) => {
    await expectTheNoteInsteadOfRunnerControls(page);
  });

  // Regression: the list said runners register "using the group token", and a runner group has none.
  test('viewer sees how runners get their tokens, and an empty group without the Add Runner form', async ({ page }) => {
    await expectTheTokenNoteAndAnEmptyGroupWithoutTheForm(page);
  });

  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/runner-group/create');
    await expectForbidden(page);
  });

  // Regression: the operator role held runner-group.create and
  // runner-group.update (m000025), so operators created and changed runner
  // groups, registered runners in any group and regenerated any runner's
  // token, and so took over the jobs of every runner group.
  test('operator sees no New Group or Edit and gets 403 on create', async ({ page }) => {
    await expectNoGroupManagementOnTheList(page);
  });

  test('operator gets 403 on changing a runner group', async ({ page }) => {
    const id = await groupId(page, SECOND_GROUP);
    await page.goto(`/runner-group/update?id=${id}`);
    await expectForbidden(page);
  });

  test('operator sees that only admins manage runners', async ({ page }) => {
    await expectTheNoteInsteadOfRunnerControls(page);
  });

  test('operator sees how runners get their tokens, and an empty group without the Add Runner form', async ({ page }) => {
    await expectTheTokenNoteAndAnEmptyGroupWithoutTheForm(page);
  });

  test('operator may not register a runner or regenerate, move or delete one', async ({ page, browser }, testInfo) => {
    // e2e-ui-*: the next e2e/seed deletes such a runner if a broken check let one through.
    const runner = `e2e-ui-op-runner-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
    // The forms of the fixture runner, as an admin's runner group page posts them.
    const forms = await asAdmin(browser, testInfo, async (admin) => {
      await openGroup(admin, SECOND_GROUP);
      const row = runnerRow(admin, FIXTURE_RUNNER);
      const actionOf = async (route: string): Promise<string> => {
        const action = (await row.locator(`form[action*="${route}"]`).first().getAttribute('action')) ?? '';
        expect(action, `the ${route} form of runner ${FIXTURE_RUNNER}`).toContain(route);
        return action;
      };
      return {
        regenerate: await actionOf('/runner/regenerate-token'),
        move: await actionOf('/runner/move'),
        delete: await actionOf('/runner/delete'),
      };
    });
    const id = await openGroup(page, SECOND_GROUP);

    const refused = {
      'register a runner': await postWithPageCsrf(page, '/runner/create', { group_id: id, name: runner }),
      'regenerate its token': await postWithPageCsrf(page, forms.regenerate),
      // Into its own group: a check that let it through would change nothing.
      'move it': await postWithPageCsrf(page, forms.move, { target_group_id: id }),
    };
    for (const [what, response] of Object.entries(refused)) {
      expect(response.status(), what).toBe(403);
    }

    await openGroup(page, SECOND_GROUP);
    await expect(runnerRow(page, runner), 'no runner registered').toHaveCount(0);
    await expect(page.locator(RUNNER_TOKEN_CARD), 'no token shown').toHaveCount(0);

    // Last, as a broken check would delete the fixture runner.
    expect((await postWithPageCsrf(page, forms.delete)).status(), 'delete it').toBe(403);
    await asAdmin(browser, testInfo, async (admin) => {
      await openGroup(admin, SECOND_GROUP);
      await expect(runnerRow(admin, FIXTURE_RUNNER), 'the runner stays in its group').toHaveCount(1);
      await expect(runnerRow(admin, runner)).toHaveCount(0);
    });
  });

  test('operator may not delete a runner group', async ({ page }) => {
    await expectDeleteForbidden(page);
  });

  test('viewer may not add a runner to a group', async ({ page }) => {
    const runner = 'e2e-ui-token-viewer-runner';
    const id = await openGroup(page, SECOND_GROUP);
    await expect(page.locator('#page-content form[action*="/runner/create"]'), 'no Add Runner form').toHaveCount(0);

    const response = await postWithPageCsrf(page, '/runner/create', { group_id: id, name: runner });

    expect(response.status()).toBe(403);
    await openGroup(page, SECOND_GROUP);
    await expect(runnerRow(page, runner)).toHaveCount(0);
    await expect(page.locator(RUNNER_TOKEN_CARD)).toHaveCount(0);
  });

  test('viewer may not delete a runner group', async ({ page }) => {
    await expectDeleteForbidden(page);
  });

  // Fixture from commands/E2eRunnerRegistrationSeeder.php
  test('viewer sees the plain HTTP warning of a runner group', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    await expect(page.getByTestId('runner-group-plaintext-warning')).toContainText('e2e-plaintext-runner');
  });
});

import { test, expect, Page } from '@playwright/test';
import { NO_ELIGIBLE_APPROVERS } from '../../lib/approvals';

/**
 * The approvers the approval rule form counts (views/approval-rule/form.php,
 * from services/ApproverCounts.php): of a role rule the users assigned the
 * role directly, of a team rule the team's members, of a users rule the
 * users chosen (each option carries data-eligible), and of each only those
 * who may decide: an active account that holds approval.decide or is a
 * superadmin. The form knows neither who will launch nor what the rule will
 * gate, so a count is the most a launch can find, and the form warns only
 * where a launch is sure to be refused: when nobody chosen may decide
 * (ApprovalRule::NO_ELIGIBLE_APPROVERS, at any number of required
 * approvals), or when fewer may than the rule requires.
 *
 * regression: the form counted every user assigned a role, inactive users
 * too. The admin role counted e2e-dormant-chief (commands/E2eAdminRoleSeeder.php),
 * who is inactive and approves nothing, so a rule that needs one approval
 * more than the admin role's active holders got no warning.
 *
 * regression: a team rule counted every member of the team, whether the
 * member may decide or not. e2e-team-beta's only member is e2e-viewer
 * (commands/E2eTeamScopingSeeder.php), whose viewer role holds no
 * approval.decide, so every launch a rule of that team gates is refused; the
 * form counted one approver and warned of nothing. A users rule counted
 * every user chosen in the same way.
 *
 * The PHPUnit counterparts, seen failing on the form from before, are in
 * tests/integration/controllers/ApprovalRuleFormRoleCountTest.php. These
 * tests only read: they count the admin role's holders on the users list and
 * save no rule.
 */

/** commands/E2eAdminRoleSeeder.php TARGET: an inactive user who holds the admin role. */
const DORMANT_ADMIN = 'e2e-dormant-chief';

/** commands/E2eTeamScopingSeeder.php: e2e-team-beta, whose only member is VIEWER. */
const VIEWER_TEAM = 'e2e-team-beta';

/** e2e-viewer holds the viewer role, without approval.decide: never an approver who may decide. */
const VIEWER = 'e2e-viewer';

/** e2e-operator and e2e-admin hold approval.decide (operator and admin roles). */
const OPERATOR = 'e2e-operator';
const ADMIN = 'e2e-admin';

/** The text of the approval rule form's script for fewer approvers who may decide than required approvals. */
function exceeds(required: number, count: number): string {
  return `Warning: Required approvals (${required}) exceeds eligible approvers (${count}).`;
}

/**
 * The users whom the users list shows with the badge `role` in its Role
 * column (the roles assigned to them), each with whether its Status is
 * Active. The list is ordered by ID and may span pages; Yii answers a page
 * number past the end with the last page, so the search stops when a page
 * repeats the previous one.
 */
async function holdersOf(page: Page, role: string): Promise<Map<string, boolean>> {
  const holders = new Map<string, boolean>();
  let previousFirstId = '';
  for (let pageNo = 1; pageNo <= 50; pageNo++) {
    await page.goto(`/user/index?page=${pageNo}`);
    const table = await page.locator('#user-table').evaluate((element) => {
      const headers = Array.from(element.querySelectorAll('thead th')).map((th) => (th.textContent ?? '').trim());
      const roleColumn = headers.indexOf('Role');
      const statusColumn = headers.indexOf('Status');
      const rows = Array.from(element.querySelectorAll('tbody tr')).map((tr) => {
        const cells = tr.querySelectorAll('td');
        return {
          id: (cells[0]?.textContent ?? '').trim(),
          username: (cells[1]?.textContent ?? '').trim(),
          roles: Array.from(cells[roleColumn]?.querySelectorAll('.badge') ?? []).map((badge) => (badge.textContent ?? '').trim()),
          status: (cells[statusColumn]?.textContent ?? '').trim(),
        };
      });
      return { roleColumn, statusColumn, rows };
    });
    expect(table.roleColumn, 'the users list has a Role column').toBeGreaterThan(-1);
    expect(table.statusColumn, 'the users list has a Status column').toBeGreaterThan(-1);
    if (table.rows.length === 0 || table.rows[0].id === previousFirstId) {
      break;
    }
    previousFirstId = table.rows[0].id;
    for (const row of table.rows.filter((candidate) => candidate.roles.includes(role))) {
      holders.set(row.username, row.status === 'Active');
    }
  }
  return holders;
}

/** Sets the Required approvals of the open rule form to `count`, as typing it does. */
async function requireApprovals(page: Page, count: number): Promise<void> {
  const required = page.locator('#required-approvals');
  await required.fill(String(count));
  await required.dispatchEvent('input');
}

/** The option of the user `username` in the Users select of the open rule form. */
function userOption(page: Page, username: string) {
  return page.locator('#approver-users option').filter({ hasText: new RegExp(`^${username}$`) });
}

test.describe('Approval Rules: the approvers a rule counts', () => {
  test('regression: the warning counts the active holders of the admin role, not e2e-dormant-chief, who is inactive', async ({ page }) => {
    const holders = await holdersOf(page, 'admin');
    expect(holders.get(DORMANT_ADMIN), `${DORMANT_ADMIN} holds the admin role and is inactive`).toBe(false);
    const active = [...holders.values()].filter((isActive) => isActive).length;
    expect(active, 'e2e-admin holds the admin role and is active').toBeGreaterThan(0);

    await page.goto('/approval-rule/create');
    await page.locator('#approver-type').selectOption('role');
    await page.locator('#approver-role').selectOption('admin');
    await requireApprovals(page, active + 1);

    // The form from before counted e2e-dormant-chief too, active + 1, and
    // showed no warning for active + 1 required approvals. Every active
    // holder of the admin role holds approval.decide, so all of them count.
    await expect(page.locator('#approver-count-warning')).toHaveText(exceeds(active + 1, active));
  });

  test('regression: a team rule counted members who may not decide', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await page.locator('#approver-type').selectOption('team');
    const team = page.locator('#approver-team option').filter({ hasText: new RegExp(`^${VIEWER_TEAM}$`) });
    await expect(team, `the form offers ${VIEWER_TEAM}`).toHaveCount(1);
    const teamId = (await team.getAttribute('value')) ?? '';
    await page.locator('#approver-team').selectOption(teamId);

    // One approval, the least a rule needs: the form from before counted
    // e2e-viewer as an approver, one for one approval, and warned of nothing.
    await requireApprovals(page, 1);
    const warning = page.locator('#approver-count-warning');
    await expect(warning).toBeVisible();
    await expect(warning).toHaveText(NO_ELIGIBLE_APPROVERS);
    // Nobody approves, so there is no launcher to tell about either.
    await expect(page.locator('#approver-launcher-note')).toBeHidden();

    // More required approvals change nothing: never "exceeds eligible approvers (0)".
    await requireApprovals(page, 3);
    await expect(warning).toHaveText(NO_ELIGIBLE_APPROVERS);
    // e2e-viewer, whom the users choice marks as one who may not decide, is the team's only member.
    await expect(userOption(page, VIEWER)).toHaveAttribute('data-eligible', '0');
    await page.goto(`/team/view?id=${teamId}`);
    const members = page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Members \(\d+\)$/ }) });
    await expect(members.locator('.card-header')).toHaveText('Members (1)');
    await expect(members.locator('tbody tr td').first()).toHaveText(VIEWER);
  });

  // The options mark who may decide (data-eligible); the form from before
  // counted every user chosen, e2e-viewer too, and its options carried no mark.
  test('regression: a users rule counted users who may not decide', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await page.locator('#approver-type').selectOption('users');
    await expect(userOption(page, VIEWER)).toHaveAttribute('data-eligible', '0');
    await expect(userOption(page, OPERATOR)).toHaveAttribute('data-eligible', '1');
    await expect(userOption(page, ADMIN)).toHaveAttribute('data-eligible', '1');
    const users = page.locator('#approver-users');
    const warning = page.locator('#approver-count-warning');
    const launcherNote = page.locator('#approver-launcher-note');

    // Only e2e-viewer: nobody may decide.
    await users.selectOption([{ label: VIEWER }]);
    await requireApprovals(page, 1);
    await expect(warning).toHaveText(NO_ELIGIBLE_APPROVERS);
    await expect(launcherNote).toBeHidden();

    // With e2e-operator, one of the two may decide: two approvals exceed that.
    await users.selectOption([{ label: VIEWER }, { label: OPERATOR }]);
    await requireApprovals(page, 2);
    await expect(warning).toHaveText(exceeds(2, 1));

    // One approval is enough, but it needs e2e-operator, who never approves their own launch.
    await requireApprovals(page, 1);
    await expect(warning).toBeHidden();
    await expect(launcherNote).toBeVisible();
  });
});

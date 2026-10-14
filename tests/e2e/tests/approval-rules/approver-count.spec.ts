import { test, expect, Page } from '@playwright/test';

/**
 * regression: the approval rule form counted every user assigned a role among
 * the role's approvers, inactive users too. The admin role counted
 * e2e-dormant-chief (commands/E2eAdminRoleSeeder.php), who is inactive and
 * approves nothing, so a rule that needs one approval more than the admin
 * role's active holders got no warning. The form counts the active users
 * assigned the role directly (views/approval-rule/form.php), the candidates
 * of a role rule whom the server lets decide, as far as the form can know.
 *
 * The PHPUnit counterpart, seen failing on the form from before, is
 * tests/integration/controllers/ApprovalRuleFormRoleCountTest.php. This test
 * only reads: it counts the admin role's holders on the users list and saves
 * no rule.
 */

/** commands/E2eAdminRoleSeeder.php TARGET: an inactive user who holds the admin role. */
const DORMANT_ADMIN = 'e2e-dormant-chief';

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

test.describe('Approval Rules: the approvers a role rule counts', () => {
  test('regression: the warning counts the active holders of the admin role, not e2e-dormant-chief, who is inactive', async ({ page }) => {
    const holders = await holdersOf(page, 'admin');
    expect(holders.get(DORMANT_ADMIN), `${DORMANT_ADMIN} holds the admin role and is inactive`).toBe(false);
    const active = [...holders.values()].filter((isActive) => isActive).length;
    expect(active, 'e2e-admin holds the admin role and is active').toBeGreaterThan(0);

    await page.goto('/approval-rule/create');
    await page.locator('#approver-type').selectOption('role');
    await page.locator('#approver-role').selectOption('admin');
    const required = page.locator('#required-approvals');
    await required.fill(String(active + 1));
    await required.dispatchEvent('input');

    // The form from before counted e2e-dormant-chief too, active + 1, and
    // showed no warning for active + 1 required approvals.
    await expect(page.locator('#approver-count-warning')).toHaveText(
      `Warning: Required approvals (${active + 1}) exceeds eligible approvers (${active}).`,
    );
  });
});

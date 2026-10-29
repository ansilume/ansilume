import { test, expect, Page } from '@playwright/test';
import { expectFlash, postWithPageCsrf } from '../../lib/helpers';
import { idWhere, indexRows } from '../../lib/scoping';

/** controllers/TeamController.php USER_ID_REQUIRED: the 400 of add-member without a user ID. */
const USER_ID_REQUIRED = 'user_id required.';

/** The usernames in the Members card of the open team page. */
async function memberNames(page: Page): Promise<string[]> {
  const names = await page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Members \(\d+\)$/ }) })
    .locator('tbody td:first-child').allTextContents();
  return names.map((name) => name.trim());
}

test.describe('Team Members', () => {
  test('team detail shows members section', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    await expect(page.locator('body')).toContainText(/member/i);
  });

  test('add member to team', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    const addBtn = page.locator('a:has-text("Add Member"), button:has-text("Add Member"), a:has-text("Add User")').first();
    if (await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await addBtn.click();
      // Select a user from the form
      const userSelect = page.locator('select[name*="user_id"]').first();
      if (await userSelect.isVisible()) {
        const options = await userSelect.locator('option:not([value=""])').all();
        if (options.length > 0) {
          const value = await options[0].getAttribute('value');
          if (value) await userSelect.selectOption(value);
        }
        await page.locator('#page-content button[type="submit"]').click();
      }
    }
  });

  // Regression: add-member read user_id with an (int) cast, so a forged
  // "<id>.5" added the user <id> (and on PHP 8.5 a JSON number beyond the int
  // range answered 500). A user_id that is no ID is refused as a missing one
  // (SubmittedId): 400, and nobody is added.
  test('a forged add-member with a user ID that is no ID answers 400 and adds nobody', async ({ page }) => {
    const teamId = idWhere(await indexRows(page, '/team/index'), 1, 'e2e-team');
    await page.goto(`/team/view?id=${teamId}`);
    const candidate = page.locator('#add-member-form select[name="user_id"] option:not([value=""])').last();
    const userId = (await candidate.getAttribute('value')) ?? '';
    const username = ((await candidate.textContent()) ?? '').trim();
    expect(userId, 'a user who is not a member yet').toMatch(/^\d+$/);

    const answer = await postWithPageCsrf(page, `/team/add-member?id=${teamId}`, { user_id: `${userId}.5` });

    expect(answer.status(), 'the forged add-member').toBe(400);
    expect(await answer.text()).toContain(USER_ID_REQUIRED);
    await page.goto(`/team/view?id=${teamId}`);
    expect(await memberNames(page), 'nobody was added').not.toContain(username);
    await expect(page.locator(`#add-member-form select[name="user_id"] option[value="${userId}"]`), 'still offered').toHaveCount(1);
  });
});

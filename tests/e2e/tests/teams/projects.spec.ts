import { test, expect, Page } from '@playwright/test';
import { postWithPageCsrf } from '../../lib/helpers';
import { idWhere, indexRows } from '../../lib/scoping';

/** controllers/TeamController.php PROJECT_ID_REQUIRED: the 400 of add-project without a project ID. */
const PROJECT_ID_REQUIRED = 'project_id required.';

/** The projects in the Project Access card of the open team page. */
async function grantedProjects(page: Page): Promise<string[]> {
  const names = await page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Project Access \(\d+\)$/ }) })
    .locator('tbody td:first-child').allTextContents();
  return names.map((name) => name.trim());
}

test.describe('Team Projects', () => {
  test('team detail shows projects section', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    await expect(page.locator('body')).toContainText(/project/i);
  });

  test('add project to team', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    const addBtn = page.locator('a:has-text("Add Project"), button:has-text("Add Project")').first();
    if (await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await addBtn.click();
      const projectSelect = page.locator('select[name*="project_id"]').first();
      if (await projectSelect.isVisible()) {
        const options = await projectSelect.locator('option:not([value=""])').all();
        if (options.length > 0) {
          const value = await options[0].getAttribute('value');
          if (value) await projectSelect.selectOption(value);
        }
        await page.locator('#page-content button[type="submit"]').click();
      }
    }
  });

  // Regression: add-project read project_id with an (int) cast, so a forged
  // "<id>.5" gave the team the project <id> (and on PHP 8.5 a JSON number
  // beyond the int range answered 500). A project_id that is no ID is refused
  // as a missing one (SubmittedId): 400, and no project is granted.
  test('a forged add-project with a project ID that is no ID answers 400 and grants nothing', async ({ page }) => {
    const teamId = idWhere(await indexRows(page, '/team/index'), 1, 'e2e-team');
    await page.goto(`/team/view?id=${teamId}`);
    const candidate = page.locator('#add-project-form select[name="project_id"] option:not([value=""])').last();
    const projectId = (await candidate.getAttribute('value')) ?? '';
    const project = ((await candidate.textContent()) ?? '').trim();
    expect(projectId, 'a project the team does not have yet').toMatch(/^\d+$/);

    const answer = await postWithPageCsrf(page, `/team/add-project?id=${teamId}`, { project_id: `${projectId}.5`, role: 'viewer' });

    expect(answer.status(), 'the forged add-project').toBe(400);
    expect(await answer.text()).toContain(PROJECT_ID_REQUIRED);
    await page.goto(`/team/view?id=${teamId}`);
    expect(await grantedProjects(page), 'no project was granted').not.toContain(project);
    await expect(page.locator(`#add-project-form select[name="project_id"] option[value="${projectId}"]`), 'still offered').toHaveCount(1);
  });
});

import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { asAdmin, forgePost, idWhere, indexRows } from '../../lib/scoping';

/**
 * An inbound trigger runs as the user who generated its token (tokens from
 * before Ansilume recorded that run as the template's creator), so the token
 * cards say whom the trigger runs as, and only a user who may operate every
 * project involved may generate a token. Only users who may change the
 * template see the card at all.
 *
 * Fixtures: commands/E2eTeamScopingSeeder.php and E2eWorkflowScopingSeeder.php.
 * e2e-mixed-wf was created by e2e-operator and carries such an older token.
 * e2e-alpha-viewed-tmpl, whose project e2e-operator's team only views, has a
 * token e2e-admin generated.
 */
async function openWorkflow(page: Page, name: string): Promise<string> {
  const id = idWhere(await indexRows(page, '/workflow-template/index'), 0, name);
  await page.goto(`/workflow-template/view?id=${id}`);
  await expect(page.locator('h2').first()).toHaveText(name);
  return id;
}

async function openTemplate(page: Page, name: string): Promise<string> {
  const id = idWhere(await indexRows(page, '/job-template/index?sort=name'), 1, name);
  await page.goto(`/job-template/view?id=${id}`);
  await expect(page.locator('h2').first()).toContainText(name);
  return id;
}

/** Revokes a token left behind by an earlier, interrupted run. */
async function revokeIfPresent(page: Page) {
  const revoke = page.locator('#page-content form[action*="/revoke-trigger-token"] button');
  if (await revoke.count() > 0) {
    page.once('dialog', (dialog) => dialog.accept());
    await revoke.click();
    await expect(page.locator('#page-content form[action*="/generate-trigger-token"] button')).toBeVisible();
  }
}

test.describe('Team Scoping — Trigger Tokens', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
    if (pn === 'admin' && !title.startsWith('admin')) test.skip();
  });

  test('admin sees that a new workflow trigger token runs as whoever generated it', async ({ page }) => {
    await openWorkflow(page, 'e2e-alpha-wf');
    await revokeIfPresent(page);
    await expect(page.locator('#wf-trigger-runs-as')).toHaveCount(0);

    await page.locator('#page-content form[action*="/generate-trigger-token"] button').click();
    await expect(page.locator('#wf-trigger-token-display')).toBeVisible();
    const runsAs = page.locator('#wf-trigger-runs-as');
    await expect(runsAs).toContainText('Launches run as e2e-admin');
    await expect(runsAs).toContainText('who generated this token');
    await expect(runsAs).toContainText('A launch is refused unless this user is active');

    // Leave the fixture without a token.
    await revokeIfPresent(page);
    await expect(page.locator('#wf-trigger-runs-as')).toHaveCount(0);
  });

  test('admin sees that an older workflow trigger token runs as the workflow\'s creator', async ({ page }) => {
    await openWorkflow(page, 'e2e-mixed-wf');
    const runsAs = page.locator('#wf-trigger-runs-as');
    await expect(runsAs).toContainText('Launches run as e2e-operator');
    await expect(runsAs).toContainText('the workflow\'s creator, because this token was generated before');
  });

  test('admin sees that a job template trigger token runs as whoever generated it', async ({ page }) => {
    await openTemplate(page, 'e2e-alpha-tmpl');
    await revokeIfPresent(page);
    await expect(page.getByTestId('trigger-not-configured')).toContainText('The trigger will run as you.');

    await page.locator('#page-content form[action*="/generate-trigger-token"] button').click();
    const runsAs = page.getByTestId('trigger-runs-as');
    await expect(runsAs).toContainText('Runs as e2e-admin, who generated the token.');
    await expect(runsAs).toContainText('Calls are refused while this user is disabled or may not launch this template.');

    await revokeIfPresent(page);
    await expect(page.getByTestId('trigger-runs-as')).toHaveCount(0);
    await expect(page.getByTestId('trigger-not-configured')).toBeVisible();
  });

  test('operator sees no trigger card on a job template their team only views, but on their team\'s template', async ({ page }) => {
    // Regression: the card, and with it whom the trigger runs as, was shown
    // to every holder of job-template.update, also on a template the team may
    // only view; its buttons then answered 403.
    await openTemplate(page, 'e2e-alpha-viewed-tmpl');
    await expect(page.locator('#page-content .card', { hasText: 'Inbound Trigger' })).toHaveCount(0);
    await expect(page.getByTestId('trigger-runs-as')).toHaveCount(0);
    await expect(page.locator('#page-content')).not.toContainText('Runs as');
    await expect(page.locator('#page-content form[action*="-trigger-token"]')).toHaveCount(0);

    await openTemplate(page, 'e2e-alpha-tmpl');
    await expect(page.locator('#page-content .card', { hasText: 'Inbound Trigger' })).toHaveCount(1);
  });

  test('operator cannot generate or revoke the trigger token of a job template their team only views', async ({ page, browser }, testInfo) => {
    // The token would run as the operator: generating it needs operator access.
    const id = await openTemplate(page, 'e2e-alpha-viewed-tmpl');
    await forgePost(page, `/job-template/generate-trigger-token?id=${id}`, {}, /\/job-template\/generate-trigger-token/);
    await expectForbidden(page);
    await page.goto(`/job-template/view?id=${id}`);
    await forgePost(page, `/job-template/revoke-trigger-token?id=${id}`, {}, /\/job-template\/revoke-trigger-token/);
    await expectForbidden(page);

    // The token e2e-admin generated is still there and still runs as e2e-admin.
    await asAdmin(browser, testInfo, async (admin) => {
      await admin.goto(`/job-template/view?id=${id}`);
      await expect(admin.getByTestId('trigger-runs-as')).toContainText('Runs as e2e-admin, who generated the token.');
    });
  });

  test('viewer is offered no trigger token on their team\'s workflow or job template', async ({ page }) => {
    // The viewer's team operates e2e-beta-proj, but the viewer role may not change templates.
    await openWorkflow(page, 'e2e-beta-wf');
    await expect(page.locator('#page-content .card', { hasText: 'Inbound Trigger' })).toHaveCount(0);
    await openTemplate(page, 'e2e-beta-tmpl');
    await expect(page.locator('#page-content .card', { hasText: 'Inbound Trigger' })).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/generate-trigger-token"]')).toHaveCount(0);
  });

  test('operator cannot generate a trigger token for a workflow they may only view', async ({ page, browser }, testInfo) => {
    const id = await openWorkflow(page, 'e2e-alpha-viewed-wf');
    await expect(page.locator('#page-content .card', { hasText: 'Inbound Trigger' })).toHaveCount(0);

    await forgePost(page, `/workflow-template/generate-trigger-token?id=${id}`, {}, /\/workflow-template\/generate-trigger-token/);
    await expectForbidden(page);

    // The operator is shown no token card at all; e2e-admin is: still no token.
    await asAdmin(browser, testInfo, async (admin) => {
      await admin.goto(`/workflow-template/view?id=${id}`);
      await expect(admin.locator('#page-content form[action*="/generate-trigger-token"] button')).toBeVisible();
      await expect(admin.locator('#wf-trigger-runs-as')).toHaveCount(0);
      await expect(admin.locator('#page-content form[action*="/revoke-trigger-token"]')).toHaveCount(0);
    });
  });
});

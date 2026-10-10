import { test, expect, Page } from '@playwright/test';
import { asAdmin, idWhere, indexRows, optionLabels } from '../../lib/scoping';

/**
 * Team scoping of the dashboard and of analytics: lists, counters, report
 * rows and filter dropdowns only cover what the signed-in user may see, and
 * quick launch only what they may launch.
 *
 * Fixtures: commands/E2eTeamScopingSeeder.php and E2eWorkflowScopingSeeder.php.
 * The finished runs of e2e-alpha-wf and e2e-beta-wf leave a succeeded job on
 * e2e-alpha-tmpl and on e2e-beta-tmpl; e2e-alpha-viewed-wf (operator's team
 * only views it) is paused, e2e-beta-approval-wf waits for approval.
 *
 * That a dashboard leaves something out only shows if it would be there for
 * someone who may see it. Recent Jobs lists the 10 newest jobs, which the
 * specs launching jobs before these push the seeded ones out of, and
 * workflow-jobs/resume.spec.ts resumes e2e-paused-workflow. So the role tests
 * first launch, as e2e-admin, what the role must not see, and check that the
 * admin's dashboard shows it.
 */
const JOB_QUICK_LAUNCH = '#page-content form[action*="/job-template/launch"] select[name="id"]';
const WORKFLOW_QUICK_LAUNCH = '#page-content form[action*="/workflow-template/launch"] select[name="id"]';

function runningWorkflows(page: Page) {
  return page.locator('#page-content .card', { hasText: 'Running Workflows' });
}

function pendingApprovals(page: Page) {
  return page.locator('#page-content .card', { hasText: 'Pending Approvals' });
}

function recentJobs(page: Page) {
  return page.locator('#page-content .card', { hasText: 'Recent Jobs' });
}

/** A link to job #`jobId`, as a selector relative to any element. */
function jobLink(jobId: string): string {
  return `a[href$="/job/view?id=${jobId}"]`;
}

/** Launches a job template through its launch form; returns the id of the new job. */
async function launchTemplate(page: Page, name: string): Promise<string> {
  // Columns of the index: #, Name, ...
  const id = idWhere(await indexRows(page, '/job-template/index?sort=name'), 1, name);
  await page.goto(`/job-template/launch?id=${id}`);
  await Promise.all([
    page.waitForURL(/\/job\/view\?id=\d+/),
    page.locator('#launch-form button[type="submit"]').click(),
  ]);
  const jobId = new URL(page.url()).searchParams.get('id');
  expect(jobId, `the job of ${name}`).toMatch(/^\d+$/);
  return jobId as string;
}

/** Launches a workflow from its page. */
async function launchWorkflow(page: Page, name: string): Promise<void> {
  // Columns of the index: Name, ...
  const id = idWhere(await indexRows(page, '/workflow-template/index'), 0, name);
  await page.goto(`/workflow-template/view?id=${id}`);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-job\/view\?id=\d+/),
    page.locator('#page-content form[action*="/workflow-template/launch"] button[type="submit"]').click(),
  ]);
}

/** Recent Jobs lists job #`jobId` of template `name`. */
async function expectRecentJob(page: Page, jobId: string, name: string) {
  const row = recentJobs(page).locator('tbody tr').filter({ has: page.locator(jobLink(jobId)) });
  await expect(row).toHaveCount(1);
  await expect(row).toContainText(name);
}

async function analytics(page: Page): Promise<{ projects: string[]; templates: string[] }> {
  await page.goto('/analytics/index');
  await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  return {
    projects: await optionLabels(page, 'select[name="project_id"]'),
    templates: await optionLabels(page, 'select[name="template_id"]'),
  };
}

test.describe('Team Scoping — Dashboard and Analytics', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
    if (pn === 'admin' && !title.startsWith('admin')) test.skip();
  });

  // -- Operator (team-alpha) ---------------------------------------------------

  test('operator is offered quick launch only for templates and workflows they may operate', async ({ page }) => {
    await page.goto('/site/index');
    const templates = await optionLabels(page, JOB_QUICK_LAUNCH);
    expect(templates).toContain('e2e-alpha-tmpl');
    expect(templates).not.toContain('e2e-beta-tmpl');
    expect(templates).not.toContain('e2e-alpha-viewed-tmpl');

    const workflows = await optionLabels(page, WORKFLOW_QUICK_LAUNCH);
    expect(workflows).toContain('e2e-alpha-wf');
    for (const name of ['e2e-beta-wf', 'e2e-mixed-wf', 'e2e-alpha-viewed-wf', 'e2e-beta-approval-wf']) {
      expect(workflows).not.toContain(name);
    }
  });

  test('operator dashboard shows no other team\'s runs, approvals or jobs', async ({ page, browser }, testInfo) => {
    // Positive control: a job of e2e-beta-tmpl launched now is the newest of
    // all, and the admin's dashboard lists it with team-beta's waiting run.
    const jobId = await asAdmin(browser, testInfo, async (admin) => {
      const id = await launchTemplate(admin, 'e2e-beta-tmpl');
      await admin.goto('/site/index');
      await expectRecentJob(admin, id, 'e2e-beta-tmpl');
      await expect(runningWorkflows(admin)).toContainText('e2e-beta-approval-wf');
      await expect(pendingApprovals(admin)).toContainText('e2e-beta-approval-rule');
      return id;
    });

    await page.goto('/site/index');
    // Visible but not operable: listed without a Resume button.
    const viewedRun = runningWorkflows(page).locator('tbody tr', { hasText: 'e2e-alpha-viewed-wf' });
    await expect(viewedRun).toHaveCount(1);
    await expect(viewedRun).toContainText('paused');
    await expect(viewedRun.locator('form[action*="/workflow-job/resume"]')).toHaveCount(0);

    await expect(recentJobs(page)).toBeVisible();
    await expect(page.locator(`#page-content ${jobLink(jobId)}`)).toHaveCount(0);
    const content = page.locator('#page-content');
    await expect(content).not.toContainText('e2e-beta-approval-wf');
    await expect(content).not.toContainText('e2e-beta-approval-rule');
    await expect(content).not.toContainText('e2e-beta-tmpl');
  });

  test('operator analytics filters and reports leave out other teams\' projects and templates', async ({ page }) => {
    const { projects, templates } = await analytics(page);
    expect(projects).toContain('e2e-alpha-proj');
    expect(projects).toContain('e2e-alpha-viewed-proj');
    expect(projects).not.toContain('e2e-beta-proj');
    expect(templates).toContain('e2e-alpha-tmpl');
    expect(templates).toContain('e2e-alpha-viewed-tmpl');
    expect(templates).not.toContain('e2e-beta-tmpl');

    await expect(page.locator('#tab-templates')).toContainText('e2e-alpha-tmpl');
    await expect(page.locator('#tab-templates')).not.toContainText('e2e-beta-tmpl');
    await expect(page.locator('#tab-projects')).not.toContainText('e2e-beta-proj');
  });

  // -- Viewer (team-beta) --------------------------------------------------------

  test('viewer dashboard shows their team\'s waiting workflow and approval, and no other team\'s', async ({ page, browser }, testInfo) => {
    // Positive control: a new run of e2e-team's e2e-paused-workflow waits at
    // its pause step, a job of e2e-alpha-tmpl launched now is the newest of
    // all, and the admin's dashboard lists both with e2e-alpha-viewed-wf.
    const jobId = await asAdmin(browser, testInfo, async (admin) => {
      await launchWorkflow(admin, 'e2e-paused-workflow');
      const id = await launchTemplate(admin, 'e2e-alpha-tmpl');
      await admin.goto('/site/index');
      await expectRecentJob(admin, id, 'e2e-alpha-tmpl');
      await expect(runningWorkflows(admin)).toContainText('e2e-paused-workflow');
      await expect(runningWorkflows(admin)).toContainText('e2e-alpha-viewed-wf');
      return id;
    });

    await page.goto('/site/index');
    await expect(runningWorkflows(page)).toContainText('e2e-beta-approval-wf');
    await expect(pendingApprovals(page)).toContainText('e2e-beta-approval-rule');

    await expect(recentJobs(page)).toBeVisible();
    await expect(page.locator(`#page-content ${jobLink(jobId)}`)).toHaveCount(0);
    const content = page.locator('#page-content');
    await expect(content).not.toContainText('e2e-alpha-viewed-wf');
    await expect(content).not.toContainText('e2e-paused-workflow');
    await expect(content).not.toContainText('e2e-alpha-tmpl');
    // No job.launch or workflow.launch: nothing to quick launch.
    await expect(page.locator(JOB_QUICK_LAUNCH)).toHaveCount(0);
    await expect(page.locator(WORKFLOW_QUICK_LAUNCH)).toHaveCount(0);
    await expect(content.locator('.card', { hasText: 'Quick Launch' })).toContainText('No templates you can launch.');
  });

  test('viewer analytics filters and reports leave out other teams\' projects and templates', async ({ page }) => {
    const { projects, templates } = await analytics(page);
    expect(projects).toContain('e2e-beta-proj');
    for (const name of ['e2e-alpha-proj', 'e2e-alpha-viewed-proj', 'e2e-project']) {
      expect(projects).not.toContain(name);
    }
    expect(templates).toContain('e2e-beta-tmpl');
    for (const name of ['e2e-alpha-tmpl', 'e2e-alpha-viewed-tmpl', 'e2e-template']) {
      expect(templates).not.toContain(name);
    }

    await expect(page.locator('#tab-templates')).toContainText('e2e-beta-tmpl');
    await expect(page.locator('#tab-templates')).not.toContainText('e2e-alpha-tmpl');
    await expect(page.locator('#tab-projects')).not.toContainText('e2e-alpha-proj');
  });

  // -- Admin -------------------------------------------------------------------------

  test('admin dashboard and analytics cover every team', async ({ page }) => {
    await page.goto('/site/index');
    const templates = await optionLabels(page, JOB_QUICK_LAUNCH);
    expect(templates).toEqual(expect.arrayContaining(['e2e-alpha-tmpl', 'e2e-beta-tmpl', 'e2e-alpha-viewed-tmpl']));
    const workflows = await optionLabels(page, WORKFLOW_QUICK_LAUNCH);
    expect(workflows).toEqual(expect.arrayContaining(['e2e-alpha-wf', 'e2e-beta-wf', 'e2e-mixed-wf', 'e2e-beta-approval-wf']));
    await expect(runningWorkflows(page)).toContainText('e2e-alpha-viewed-wf');
    await expect(runningWorkflows(page)).toContainText('e2e-beta-approval-wf');
    await expect(pendingApprovals(page)).toContainText('e2e-beta-approval-rule');

    const filters = await analytics(page);
    expect(filters.projects).toEqual(expect.arrayContaining(['e2e-alpha-proj', 'e2e-beta-proj']));
    expect(filters.templates).toEqual(expect.arrayContaining(['e2e-alpha-tmpl', 'e2e-beta-tmpl']));
    await expect(page.locator('#tab-templates')).toContainText('e2e-alpha-tmpl');
    await expect(page.locator('#tab-templates')).toContainText('e2e-beta-tmpl');
  });
});

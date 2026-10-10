import { test, expect, Page } from '@playwright/test';
import { expectFlash, expectForbidden } from '../../lib/helpers';
import { asAdmin, forgePost, idWhere, indexRows, optionLabels, rowsWhere } from '../../lib/scoping';

/**
 * Team scoping of workflow templates. A workflow belongs to the projects of
 * its job steps: seeing it needs view access to every one, changing or
 * launching it operator access to every one.
 *
 * Fixtures (commands/E2eTeamScopingSeeder.php, E2eWorkflowScopingSeeder.php):
 * e2e-operator's team operates e2e-alpha-proj and only views
 * e2e-alpha-viewed-proj; e2e-viewer's team operates e2e-beta-proj.
 *   e2e-alpha-wf         job step on e2e-alpha-tmpl
 *   e2e-alpha-viewed-wf  pause step, job step on e2e-alpha-viewed-tmpl
 *   e2e-beta-wf          job step on e2e-beta-tmpl
 *   e2e-mixed-wf         job steps on e2e-alpha-tmpl and e2e-beta-tmpl (created by e2e-operator)
 *   e2e-beta-approval-wf approval step, job step on e2e-beta-tmpl
 *   e2e-pause-only-wf    pause step only: no job step, no project, visible to everyone
 *   e2e-alpha-legacy-wf  job step on e2e-alpha-tmpl, pause step that still carries
 *                        e2e-beta-tmpl, as older versions saved pause steps
 */
const INDEX = '/workflow-template/index';
const TEMPLATES = '/job-template/index?sort=name';

async function workflowNames(page: Page): Promise<string[]> {
  return (await indexRows(page, INDEX)).map((row) => row.cells[0]);
}

async function openWorkflow(page: Page, name: string): Promise<string> {
  const id = idWhere(await indexRows(page, INDEX), 0, name);
  await page.goto(`/workflow-template/view?id=${id}`);
  await expect(page.locator('h2').first()).toHaveText(name);
  return id;
}

/** The ids of the newest workflow.launch.denied audit entries, newest first, with their rows. */
async function deniedLaunches(admin: Page): Promise<{ id: number; cells: string[] }[]> {
  await admin.goto('/audit-log/index?action=workflow.launch.denied');
  const rows = await admin.locator('#page-content table.table tbody tr').evaluateAll((trs) => trs.map((tr) => ({
    id: Number(tr.querySelector('a')?.textContent ?? '0'),
    cells: Array.from(tr.querySelectorAll('td')).map((td) => (td.textContent ?? '').replace(/\s+/g, ' ').trim()),
  })));
  return rows.filter((row) => row.id > 0);
}

test.describe('Team Scoping — Workflow Templates', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
    if (pn === 'admin' && !title.startsWith('admin')) test.skip();
  });

  // -- Operator (team-alpha) ---------------------------------------------------

  test('operator sees the workflows of projects their team may view, and no others', async ({ page }) => {
    const names = await workflowNames(page);
    expect(names).toContain('e2e-alpha-wf');
    expect(names).toContain('e2e-alpha-viewed-wf');
    expect(names).toContain('e2e-pause-only-wf');
    expect(names).not.toContain('e2e-beta-wf');
    expect(names).not.toContain('e2e-beta-approval-wf');
    // One step on another team's template hides the whole workflow, also from its creator.
    expect(names).not.toContain('e2e-mixed-wf');
  });

  test('operator is offered Launch on the index only for workflows they may operate', async ({ page }) => {
    const rows = await indexRows(page, INDEX);
    const launches = (name: string) => {
      const matches = rowsWhere(rows, 0, name);
      expect(matches, `one listed row for ${name}`).toHaveLength(1);
      return matches[0].forms.filter((action) => action.includes('/workflow-template/launch'));
    };
    expect(launches('e2e-alpha-wf')).toHaveLength(1);
    expect(launches('e2e-alpha-viewed-wf')).toHaveLength(0);
  });

  test('operator gets 403 on the view of a workflow with another team\'s step', async ({ page, browser }, testInfo) => {
    const ids = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, INDEX);
      return ['e2e-beta-wf', 'e2e-mixed-wf', 'e2e-beta-approval-wf'].map((name) => idWhere(rows, 0, name));
    });
    for (const id of ids) {
      await page.goto(`/workflow-template/view?id=${id}`);
      await expectForbidden(page);
      await expect(page.locator('#workflow-steps-table')).toHaveCount(0);
    }
  });

  test('operator may view but not change or launch a workflow of a project their team only views', async ({ page }) => {
    await openWorkflow(page, 'e2e-alpha-viewed-wf');
    const content = page.locator('#page-content');
    await expect(page.locator('#wf-view-only-notice')).toBeVisible();
    await expect(page.locator('#workflow-steps-table')).toContainText('e2e-alpha-viewed-tmpl');
    await expect(content.locator('form[action*="/workflow-template/launch"]')).toHaveCount(0);
    await expect(content.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
    await expect(content.locator('form[action*="/workflow-template/delete"]')).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toHaveCount(0);
    await expect(content.locator('form[action*="/workflow-template/move-step"]')).toHaveCount(0);
    await expect(content.locator('form[action*="/workflow-template/remove-step"]')).toHaveCount(0);
    await expect(content.locator('.card', { hasText: 'Inbound Trigger' })).toHaveCount(0);
  });

  test('operator may change and launch a workflow whose every step their team operates', async ({ page }) => {
    await openWorkflow(page, 'e2e-alpha-wf');
    const content = page.locator('#page-content');
    await expect(page.locator('#wf-view-only-notice')).toHaveCount(0);
    await expect(content.locator('form[action*="/workflow-template/launch"] button')).toBeVisible();
    await expect(content.getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
    await expect(page.locator('#add-step-form')).toBeVisible();
  });

  test('operator may change and launch a workflow whose pause step an older version saved with another team\'s job template', async ({ page }) => {
    // Regression: the leftover on the pause step restricted the workflow as
    // if the step ran that job template. Only job steps count, and a step
    // shows only the target of its type, so the leftover is never named.
    expect(await workflowNames(page)).toContain('e2e-alpha-legacy-wf');
    await openWorkflow(page, 'e2e-alpha-legacy-wf');
    const content = page.locator('#page-content');
    const steps = page.locator('#workflow-steps-table');
    await expect(steps).toContainText('e2e-alpha-legacy-wf-pause');
    await expect(steps).toContainText('e2e-alpha-tmpl');
    await expect(content).not.toContainText('e2e-beta-tmpl');
    await expect(page.locator('#wf-view-only-notice')).toHaveCount(0);
    await expect(content.locator('form[action*="/workflow-template/launch"] button')).toBeVisible();
  });

  test('operator gets 403 on the edit form of a workflow they may only view', async ({ page }) => {
    const id = await openWorkflow(page, 'e2e-alpha-viewed-wf');
    await page.goto(`/workflow-template/update?id=${id}`);
    await expectForbidden(page);
  });

  test('operator cannot launch workflows they may not operate with a forged POST, and nothing runs', async ({ page, browser }, testInfo) => {
    const names = ['e2e-beta-wf', 'e2e-mixed-wf', 'e2e-alpha-viewed-wf'];
    const before = await asAdmin(browser, testInfo, async (admin) => {
      const templates = await indexRows(admin, INDEX);
      const jobTemplates = await indexRows(admin, TEMPLATES);
      const runs = await indexRows(admin, '/workflow-job/index');
      const denied = await deniedLaunches(admin);
      return {
        ids: names.map((name) => idWhere(templates, 0, name)),
        betaTmpl: idWhere(jobTemplates, 1, 'e2e-beta-tmpl'),
        viewedTmpl: idWhere(jobTemplates, 1, 'e2e-alpha-viewed-tmpl'),
        runs: names.map((name) => rowsWhere(runs, 1, name).length),
        newestDenied: denied.length > 0 ? denied[0].id : 0,
      };
    });
    // The refusal names job templates only to a user who may see the
    // workflow: e2e-beta-wf and e2e-mixed-wf are hidden from the operator,
    // e2e-alpha-viewed-wf is not.
    const refusals = [
      'You may not launch this workflow.',
      'You may not launch this workflow.',
      `You may not launch job template(s) #${before.viewedTmpl} of this workflow.`,
    ];

    for (const [index, id] of before.ids.entries()) {
      // A real form post with a valid CSRF token: only the access check may stop it.
      await page.goto('/workflow-template/index');
      await forgePost(page, `/workflow-template/launch?id=${id}`, {}, /\/workflow-template\/launch/);
      await expectForbidden(page);
      await expect(page.locator('body')).toContainText(refusals[index]);
      await expect(page.locator('body'), 'no ID of another team\'s job template').not.toContainText(new RegExp(`#${before.betaTmpl}\\b`));
    }

    await asAdmin(browser, testInfo, async (admin) => {
      const runs = await indexRows(admin, '/workflow-job/index');
      expect(names.map((name) => rowsWhere(runs, 1, name).length), 'no workflow run was created').toEqual(before.runs);
      // Every refusal is audited as workflow.launch.denied, for the operator and the workflow.
      const fresh = (await deniedLaunches(admin)).filter((row) => row.id > before.newestDenied);
      for (const id of before.ids) {
        const entry = fresh.filter((row) => row.cells.includes(`workflow_template #${id}`));
        expect(entry, `a workflow.launch.denied entry for workflow_template #${id}`).toHaveLength(1);
        expect(entry[0].cells[2]).toBe('e2e-operator');
      }
    });
  });

  test('operator is offered only job templates they may operate for a new step', async ({ page }) => {
    await openWorkflow(page, 'e2e-alpha-wf');
    const labels = await optionLabels(page, '#workflowstep-job_template_id');
    expect(labels).toContain('e2e-alpha-tmpl');
    expect(labels).not.toContain('e2e-beta-tmpl');
    expect(labels).not.toContain('e2e-alpha-viewed-tmpl');
  });

  test('operator cannot add a step with a job template they may not operate', async ({ page, browser }, testInfo) => {
    const [betaTmpl, viewedTmpl] = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, TEMPLATES);
      return [idWhere(rows, 1, 'e2e-beta-tmpl'), idWhere(rows, 1, 'e2e-alpha-viewed-tmpl')];
    });
    const workflowId = await openWorkflow(page, 'e2e-alpha-wf');
    const step = (templateId: string) => ({
      'WorkflowStep[name]': 'e2e-forged-step',
      'WorkflowStep[step_type]': 'job',
      'WorkflowStep[step_order]': '20',
      'WorkflowStep[job_template_id]': templateId,
    });

    // Another team's template, which the operator cannot see: reported as not existing.
    await page.goto('/workflow-template/index');
    await forgePost(page, `/workflow-template/add-step?id=${workflowId}`, step(betaTmpl), /\/workflow-template\/view/);
    await expectFlash(page, 'danger', 'Step not added: The selected job template does not exist.');

    // A template the operator sees but may not operate: refused.
    await forgePost(page, `/workflow-template/add-step?id=${workflowId}`, step(viewedTmpl), /\/workflow-template\/add-step/);
    await expectForbidden(page);

    await openWorkflow(page, 'e2e-alpha-wf');
    await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(1);
    await expect(page.locator('#workflow-steps-table')).not.toContainText('e2e-forged-step');
  });

  test('operator cannot add a step with a zero-padded ID of a job template they may not operate', async ({ page, browser }, testInfo) => {
    // Regression: the check read the ID with filter_var(), which rejects a
    // leading zero, so "0<id>" skipped it while the step stored <id>.
    const [betaTmpl, viewedTmpl] = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, TEMPLATES);
      return [idWhere(rows, 1, 'e2e-beta-tmpl'), idWhere(rows, 1, 'e2e-alpha-viewed-tmpl')];
    });
    const workflowId = await openWorkflow(page, 'e2e-alpha-wf');
    const paddedStep = (templateId: string) => ({
      'WorkflowStep[name]': 'e2e-padded-step',
      'WorkflowStep[step_type]': 'job',
      'WorkflowStep[step_order]': '20',
      'WorkflowStep[job_template_id]': `0${templateId}`,
    });

    // Another team's template, which the operator cannot see: reported as not existing.
    await page.goto('/workflow-template/index');
    await forgePost(page, `/workflow-template/add-step?id=${workflowId}`, paddedStep(betaTmpl), /\/workflow-template\/view/);
    await expectFlash(page, 'danger', 'Step not added: The selected job template does not exist.');

    // A template the operator sees but may not operate: refused.
    await forgePost(page, `/workflow-template/add-step?id=${workflowId}`, paddedStep(viewedTmpl), /\/workflow-template\/add-step/);
    await expectForbidden(page);

    await openWorkflow(page, 'e2e-alpha-wf');
    await expect(page.locator('#workflow-steps-table tbody tr')).toHaveCount(1);
    await expect(page.locator('#workflow-steps-table')).not.toContainText('e2e-padded-step');
  });

  // -- Viewer (team-beta) --------------------------------------------------------

  test('viewer sees the workflows of their team\'s projects, and no others', async ({ page }) => {
    const names = await workflowNames(page);
    expect(names).toContain('e2e-beta-wf');
    expect(names).toContain('e2e-beta-approval-wf');
    expect(names).toContain('e2e-pause-only-wf');
    expect(names).not.toContain('e2e-alpha-wf');
    expect(names).not.toContain('e2e-alpha-viewed-wf');
    expect(names).not.toContain('e2e-mixed-wf');
  });

  test('viewer sees no Launch, Edit or step forms on a workflow of their own team', async ({ page }) => {
    // The viewer's team holds the operator role on e2e-beta-proj: only the
    // viewer RBAC role keeps these hidden.
    await openWorkflow(page, 'e2e-beta-wf');
    const content = page.locator('#page-content');
    await expect(page.locator('#workflow-steps-table')).toContainText('e2e-beta-tmpl');
    await expect(content.locator('form[action*="/workflow-template/launch"]')).toHaveCount(0);
    await expect(content.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toHaveCount(0);
  });

  test('viewer gets 403 on the view of another team\'s workflow', async ({ page, browser }, testInfo) => {
    const ids = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, INDEX);
      return ['e2e-alpha-wf', 'e2e-mixed-wf'].map((name) => idWhere(rows, 0, name));
    });
    for (const id of ids) {
      await page.goto(`/workflow-template/view?id=${id}`);
      await expectForbidden(page);
    }
  });

  // -- Admin -------------------------------------------------------------------------

  test('admin sees every team\'s workflows and may change them', async ({ page }) => {
    const names = await workflowNames(page);
    for (const name of ['e2e-alpha-wf', 'e2e-alpha-viewed-wf', 'e2e-beta-wf', 'e2e-mixed-wf', 'e2e-beta-approval-wf', 'e2e-pause-only-wf']) {
      expect(names).toContain(name);
    }
    await openWorkflow(page, 'e2e-mixed-wf');
    await expect(page.locator('#wf-view-only-notice')).toHaveCount(0);
    await expect(page.locator('#add-step-form')).toBeVisible();
    const labels = await optionLabels(page, '#workflowstep-job_template_id');
    expect(labels).toContain('e2e-alpha-tmpl');
    expect(labels).toContain('e2e-beta-tmpl');
    expect(labels).toContain('e2e-alpha-viewed-tmpl');
  });
});

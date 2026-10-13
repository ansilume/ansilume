import { test, expect } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { indexRows, rowsWhere } from '../../lib/scoping';
import { flash } from '../../lib/approvals';
import { TEMPLATE_DELETE_CONFIRM, deleteOpenTemplate, templateDeleted } from '../../lib/job-templates';

/** Seeded by commands/E2eWorkflowGateSeeder.php; no runner serves it, so its jobs stay queued. */
const NO_RUNNER_GROUP = 'e2e-gate-runner-group';

/**
 * Deleting a job template (services/JobTemplateDeletionService.php): its
 * waiting jobs are canceled and its enabled schedules disabled, and the
 * flash counts both. The template is created here, on the seeded project and
 * inventory, in a runner group no runner serves; its queued job comes from
 * the E2E hook /e2e/create-cancelable-job.
 */
test.describe('Delete a Job Template', () => {
  // Regression: deleting a job template left its waiting jobs queued and its schedules enabled.
  test('deleting a template cancels its waiting job and disables its schedule', async ({ page, request, baseURL }) => {
    const name = `e2e-delete-template-${Date.now()}`;
    const scheduleName = `${name}-schedule`;

    await page.goto('/job-template/create');
    await page.locator('#jobtemplate-name').fill(name);
    await page.locator('#jobtemplate-project_id').selectOption({ label: 'e2e-project' });
    await page.locator('#jobtemplate-playbook').fill('site.yml');
    await page.locator('#jobtemplate-inventory_id').selectOption({ label: 'e2e-inventory' });
    // A group no runner serves (seeded by E2eWorkflowGateSeeder): in a group
    // with live runners, one claims the queued job before the delete, and the
    // delete rightly leaves a running job alone.
    await page.locator('#jobtemplate-runner_group_id').selectOption({ label: NO_RUNNER_GROUP });
    await Promise.all([
      page.waitForURL(/\/job-template\/view\?id=\d+/),
      page.getByRole('button', { name: 'Create Template' }).click(),
    ]);
    const templateId = new URL(page.url()).searchParams.get('id') ?? '';

    const created = await request.post(`${baseURL}/e2e/create-cancelable-job`, { params: { template: name } });
    expect(created.status()).toBe(200);
    const { job_id: jobId } = (await created.json()) as { job_id: number };

    await page.goto('/schedule/create');
    await page.locator('#schedule-name').fill(scheduleName);
    await page.locator('#schedule-job_template_id').selectOption(templateId);
    await page.locator('#cron-input').fill('0 0 1 1 *');
    await expect(page.locator('#schedule-enabled')).toBeChecked();
    await submitForm(page);
    await expectFlash(page, 'success');

    await page.goto(`/job-template/view?id=${templateId}`);
    expect(await deleteOpenTemplate(page)).toBe(TEMPLATE_DELETE_CONFIRM);
    await expect(flash(page, 'success')).toHaveText(templateDeleted(name, 1, 1));

    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#status-badge')).toHaveText('Canceled');

    // Columns of the schedule list: #, Name, Template, Cron, Timezone, Next Run, Last Run, Status.
    const schedules = rowsWhere(await indexRows(page, '/schedule/index'), 1, scheduleName);
    expect(schedules).toHaveLength(1);
    expect(schedules[0].cells[2]).toBe('Template missing');
    expect(schedules[0].cells[7]).toBe('Disabled');

    await page.goto(`/job-template/view?id=${templateId}`);
    await expect(page.locator('body')).toContainText(/Not Found/);
  });
});

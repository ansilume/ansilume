import { test, expect, Page } from '@playwright/test';
import { forgePost, optionValue } from '../../lib/scoping';
import { deleteOpenTemplate } from '../../lib/job-templates';
import { LINT_QUEUED, LINT_RAN, expectLintResult, lintStatus } from '../../lib/lint';

// Regression: saving a job template waited for ansible-lint, which ran in
// the request: about two seconds for a small playbook, up to 180 seconds
// while another lint run held the lock of the checkout. A save queues the
// run now, which the queue-worker makes (LintService::queueForTemplate()):
// the template's page shows "Lint queued" and reloads itself once the
// result is stored.

/**
 * Creates the job template `name` on e2e-project (site.yml, which
 * commands/E2eController.php writes into the runtime directory, where the
 * queue-worker sees it) through a real form post; its ID. Its page opens.
 */
async function createTemplate(page: Page, name: string): Promise<string> {
  await page.goto('/job-template/create');
  const fields = {
    'JobTemplate[name]': name,
    'JobTemplate[project_id]': await optionValue(page, '#jobtemplate-project_id', 'e2e-project'),
    'JobTemplate[playbook]': 'site.yml',
    'JobTemplate[inventory_id]': await optionValue(page, '#jobtemplate-inventory_id', 'e2e-inventory'),
    'JobTemplate[runner_group_id]': (await page.locator('#jobtemplate-runner_group_id option:not([value=""])').first().getAttribute('value')) ?? '',
    'JobTemplate[verbosity]': '0',
    'JobTemplate[forks]': '5',
    'JobTemplate[timeout_minutes]': '30',
  };
  await page.goto('/job-template/index');
  await forgePost(page, '/job-template/create', fields, /\/job-template\/view\?id=\d+$/);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

test.describe('Job template lint queue', () => {
  test('saving a job template shows Lint queued, then the result without a reload', async ({ page }) => {
    test.setTimeout(240_000);
    const id = await createTemplate(page, `e2e-lint-queue-${Date.now()}`);
    try {
      // Right after the save: the run is queued, and nothing is linted yet.
      await expect(page.getByTestId('lint-queued')).toHaveText(LINT_QUEUED);
      await expect(page.getByTestId('lint-queued-at')).toContainText('since ');

      await expectLintResult(page);
      await expect(page.getByTestId('lint-badge')).toHaveText(LINT_RAN);
      await expect(page.locator('#template-lint-output')).not.toContainText('Project path not found');
      const answer = await lintStatus(page, 'job-template', id);
      expect(answer.status()).toBe(200);
      const status = (await answer.json()) as { queued: boolean; lint_at: number | null };
      expect(status.queued).toBe(false);
      expect(typeof status.lint_at).toBe('number');

      // An edit queues the next run; the stored result stays, marked as the previous run.
      await page.goto(`/job-template/update?id=${id}`);
      await page.locator('#jobtemplate-description').fill('E2E lint queue');
      await Promise.all([
        page.waitForURL(/\/job-template\/view\?id=\d+$/),
        page.getByRole('button', { name: 'Save Changes' }).click(),
      ]);
      await expect(page.getByTestId('lint-queued')).toHaveText(LINT_QUEUED);
      await expect(page.getByTestId('lint-previous')).toContainText('Previous run, ');
      await expect(page.locator('#template-lint-output')).not.toBeEmpty();
      await expectLintResult(page);
      await expect(page.getByTestId('lint-previous')).toHaveCount(0);
    } finally {
      await page.goto(`/job-template/view?id=${id}`);
      await deleteOpenTemplate(page);
    }
  });
});

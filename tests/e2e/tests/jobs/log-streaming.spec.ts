import { test, expect } from '@playwright/test';
import { LOGSTREAM_FINISHED_JOB, LOGSTREAM_RUNNING_JOB, seededJobId } from '../../lib/jobs';

/**
 * Job logs on the job page, on the jobs commands/E2eLogStreamSeeder.php seeds:
 * a finished job with three log chunks and a running one with two.
 *
 * The tests used to look for those jobs by their execution command in the
 * rows of the job list's first page, which shows no execution command, and
 * skipped themselves when they did not find one: they never ran.
 */
test.describe('Job Log Streaming', () => {
  test('finished job renders all log chunks', async ({ page }) => {
    await page.goto(`/job/view?id=${await seededJobId(page, LOGSTREAM_FINISHED_JOB)}`);

    await expect(page.locator('#job-log')).toContainText('PLAY [localhost]', { timeout: 5_000 });
    await expect(page.locator('#job-log')).toContainText('TASK [Gathering Facts]', { timeout: 5_000 });
    await expect(page.locator('#job-log')).toContainText('PLAY RECAP', { timeout: 5_000 });
    await expect(page.locator('#status-badge')).toHaveText('Succeeded');
  });

  test('running job polls and shows initial chunks', async ({ page }) => {
    const id = await seededJobId(page, LOGSTREAM_RUNNING_JOB);
    const poll = page.waitForRequest((req) => req.url().includes(`/job/log-poll?id=${id}`), { timeout: 10_000 });
    await page.goto(`/job/view?id=${id}`);

    await expect(page.locator('#job-log')).toContainText('PLAY [webservers]', { timeout: 5_000 });
    await expect(page.locator('#job-log')).toContainText('install package', { timeout: 5_000 });

    // The page polls the log of a job that has not finished.
    await poll;
  });
});

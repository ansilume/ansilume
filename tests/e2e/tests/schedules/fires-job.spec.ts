import { test, expect } from '@playwright/test';

// Drives the scheduled-execution path end-to-end: a debug-only HTTP hook
// forces a seeded schedule to be due and then runs ScheduleService once.
// Catches regressions where ScheduleService stops matching due schedules or
// JobLaunchService stops persisting the Job row — both of which today only
// have PHPUnit coverage and would silently break the cron runner in
// production. The /e2e/fire-schedule endpoint is gated on YII_DEBUG (see
// controllers/E2eController.php) so it never surfaces on prod.

test.describe('Schedule fires job', () => {
  test('firing a seeded schedule inserts a Job visible in /job/view', async ({ page, request, baseURL }) => {
    const fireResponse = await request.post(`${baseURL}/e2e/fire-schedule`, {
      params: { name: 'e2e-schedule' },
    });
    expect(fireResponse.status(), `fire-schedule returned ${fireResponse.status()}`).toBe(200);
    const result = (await fireResponse.json()) as { launched: number; latest_job_id: number };

    expect(result.launched).toBeGreaterThanOrEqual(1);
    expect(result.latest_job_id).toBeGreaterThan(0);

    // Verify the new job is discoverable through the UI — visiting the
    // freshly-inserted row's view page proves the record made it through
    // the controller/model layer, not just the DB count.
    await page.goto(`/job/view?id=${result.latest_job_id}`);
    await expect(page.locator('h1, h2').first()).toContainText(/job\s*#?\d+/i);
    await expect(page.locator('body')).toContainText('e2e-template');
  });
});

import { test, expect } from '@playwright/test';

// Live-polling sync log panel: when a project is in STATUS_SYNCING the
// detail page renders a card with the captured stdout/stderr lines and
// polls /project/sync-status?id=N&since=SEQ to keep the badge and log up
// to date. The /e2e/seed-syncing-project hook forces the seeded project
// into SYNCING + seeded log lines so the spec has predictable content.

test.describe('Project sync log panel', () => {
  test('renders captured log lines and polling endpoint returns JSON', async ({ page, request, baseURL }) => {
    const seedResponse = await request.post(`${baseURL}/e2e/seed-syncing-project`, {
      params: { name: 'e2e-project' },
    });
    expect(seedResponse.status()).toBe(200);
    const { project_id: projectId } = (await seedResponse.json()) as { project_id: number };

    await page.goto(`/project/view?id=${projectId}`);
    const card = page.locator('#sync-log-card');
    await expect(card).toBeVisible();
    await expect(card.locator('#sync-status-badge')).toContainText(/syncing/i);
    await expect(card.locator('#sync-log-output')).toContainText('Cloning into');
    await expect(card.locator('#sync-log-output')).toContainText('Receiving objects: 100%');

    // The polling endpoint must return the same view data shape the JS
    // poller expects, including a non-empty logs array.
    const status = await request.get(
      `${baseURL}/project/sync-status?id=${projectId}&since=0`,
    );
    expect(status.status()).toBe(200);
    const body = (await status.json()) as { is_syncing: boolean; logs: Array<{ content: string }> };
    expect(body.is_syncing).toBe(true);
    expect(body.logs.length).toBeGreaterThanOrEqual(3);
    expect(body.logs.some((l) => l.content.includes('Cloning into'))).toBe(true);

    // Worker block must always be present — operators rely on it to spot a
    // dead worker even when the sync hasn't started yet.
    expect(body).toHaveProperty('worker');
    expect(typeof body.worker).toBe('object');
    expect(body.worker).toHaveProperty('alive');
    expect(typeof body.worker.alive).toBe('boolean');
    expect(typeof body.worker.count).toBe('number');
    expect(body.worker.stale_after_seconds).toBe(120);
    expect(body.worker.stale_code_warn_seconds).toBe(86400);

    // The worker indicator footer is wired into the panel and should be
    // populated by the first poll. We don't pin alive/dead state because
    // the dev queue-worker container may or may not be up at test time.
    const indicator = page.locator('#sync-worker-indicator');
    await expect(indicator).toBeVisible({ timeout: 5_000 });

    // Reset the seeded project so subsequent specs see a clean fixture.
    await request.post(`${baseURL}/e2e/seed-syncing-project`, {
      params: { name: 'e2e-project', reset: 1 },
    });
  });
});

import { test, expect } from '@playwright/test';

test.describe('Job Log Streaming', () => {
  async function gotoJobByExecutionCommand(
    page: import('@playwright/test').Page,
    execCmd: string,
  ): Promise<boolean> {
    await page.goto('/job/index');
    const rows = page.locator('table.table tbody tr');
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
      const row = rows.nth(i);
      const text = await row.innerText();
      if (text.includes(execCmd)) {
        const link = row.locator('a').first();
        const href = await link.getAttribute('href');
        if (href) {
          await page.goto(href);
          return true;
        }
      }
    }
    return false;
  }

  test('finished job renders all log chunks', async ({ page }) => {
    const found = await gotoJobByExecutionCommand(page, 'e2e-logstream-finished');
    if (!found) {
      test.skip(true, 'No e2e-logstream-finished job seeded');
      return;
    }

    await expect(page.locator('#job-log')).toContainText('PLAY [localhost]', { timeout: 5_000 });
    await expect(page.locator('#job-log')).toContainText('TASK [Gathering Facts]', { timeout: 5_000 });
    await expect(page.locator('#job-log')).toContainText('PLAY RECAP', { timeout: 5_000 });
    await expect(page.locator('#status-badge')).toContainText(/success/i, { timeout: 5_000 });
  });

  test('running job polls and shows initial chunks', async ({ page }) => {
    const pollRequests: string[] = [];
    page.on('request', (req) => {
      const url = req.url();
      if (url.includes('/log-poll')) {
        pollRequests.push(url);
      }
    });

    const found = await gotoJobByExecutionCommand(page, 'e2e-logstream-running');
    if (!found) {
      test.skip(true, 'No e2e-logstream-running job seeded');
      return;
    }

    await expect(page.locator('#job-log')).toContainText('PLAY [webservers]', { timeout: 5_000 });
    await expect(page.locator('#job-log')).toContainText('install package', { timeout: 5_000 });

    // Give the polling JS time to fire at least once.
    await page.waitForRequest((req) => req.url().includes('/log-poll'), { timeout: 10_000 });
    expect(pollRequests.length).toBeGreaterThan(0);
  });
});

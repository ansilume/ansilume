import { test, expect } from '@playwright/test';
import {
  DELETED_TEMPLATE_SCHEDULE,
  SCHEDULE_STATUS_COLUMN,
  SCHEDULE_TEMPLATE_COLUMN,
  flash,
  scheduleEnableRefused,
  scheduleRow,
  toggleOpenSchedule,
} from '../../lib/approvals';

/**
 * Turning schedules on and off. Fixtures: e2e-schedule
 * (commands/E2eController.php) and e2e-deleted-template-schedule, the
 * disabled schedule of the soft-deleted job template e2e-deleted-template
 * (commands/E2eApprovalSeeder.php).
 */
const TEMPLATE = SCHEDULE_TEMPLATE_COLUMN;
const STATUS = SCHEDULE_STATUS_COLUMN;
const ENABLE_REFUSED = scheduleEnableRefused(DELETED_TEMPLATE_SCHEDULE);

test.describe('Schedule Toggle', () => {
  test('schedule detail shows enable/disable toggle', async ({ page }) => {
    const row = await scheduleRow(page, 'e2e-schedule');
    await page.goto(`/schedule/view?id=${row.id}`);
    await expect(page.locator('#page-content form[action*="/schedule/toggle"] button')).toHaveText(/^\s*(Enable|Disable)\s*$/);
    await expect(page.locator('#page-content dl')).toContainText(/Enabled|Disabled/);
  });

  // Regression: a schedule of a deleted template stayed enabled and could be re-enabled.
  test('enabling the schedule of a deleted job template is refused, and it stays disabled', async ({ page }) => {
    const before = await scheduleRow(page, DELETED_TEMPLATE_SCHEDULE);
    expect(before.cells[TEMPLATE]).toBe('Template missing');
    expect(before.cells[STATUS]).toBe('Disabled');

    await page.goto(`/schedule/view?id=${before.id}`);
    await expect(page.locator('#page-content form[action*="/schedule/toggle"] button')).toHaveText(/^\s*Enable\s*$/);
    await toggleOpenSchedule(page);

    await expect(flash(page, 'danger')).toHaveText(ENABLE_REFUSED);
    await expect(flash(page, 'success')).toHaveCount(0);
    expect((await scheduleRow(page, DELETED_TEMPLATE_SCHEDULE)).cells[STATUS]).toBe('Disabled');
    await page.goto(`/schedule/view?id=${before.id}`);
    const details = page.locator('#page-content dl');
    await expect(details).toContainText('Disabled');
    await expect(details).not.toContainText('Enabled');
  });
});

import { test, expect, Page } from '@playwright/test';
import { DELETED_TEMPLATE_SCHEDULE, SCHEDULE_TEMPLATE_COLUMN, scheduleRow } from '../../lib/approvals';
import { HOST_INVENTORY, HOST_RULE, HOST_SCHEDULE, HOST_TEMPLATE } from '../../lib/inventories';
import { SCHEDULE_NOTE, SCHEDULE_NOTE_INVENTORY } from '../../lib/job-templates';
import { RUN_AS_SCHEDULE, RUN_AS_TEMPLATE } from '../../lib/schedules';

/**
 * The schedule page says whether each run of the schedule waits for
 * approval, and for which rule (views/schedule/view.php, the Approval row
 * right after Runs as; services/JobTemplateGate.php scheduleNote()): the
 * rule of the job template, or the rule of its inventory. Fixtures:
 * e2e-run-as-schedule (commands/E2eScheduleRunAsSeeder.php), of a template
 * with e2e-approval-rule as its own rule; e2e-host-gated-schedule
 * (commands/E2eInventoryGateSeeder.php), of a template without a rule of its
 * own on e2e-gated-inventory; e2e-schedule, of the ungated e2e-template; and
 * e2e-deleted-template-schedule, of a deleted template.
 */

/** The labels of the open schedule page's Details rows, in order. */
async function detailLabels(page: Page): Promise<string[]> {
  return (await page.locator('#page-content dl dt').allTextContents()).map((label) => label.trim());
}

/** Opens the page of the schedule `name`, found in the schedule list; its row there. */
async function openSchedule(page: Page, name: string): Promise<string[]> {
  const row = await scheduleRow(page, name);
  await page.goto(`/schedule/view?id=${row.id}`);
  return row.cells;
}

test.describe('Schedule approval note', () => {
  test('a schedule of a template with a rule of its own says each run waits for that rule', async ({ page }) => {
    const cells = await openSchedule(page, RUN_AS_SCHEDULE);
    expect(cells[SCHEDULE_TEMPLATE_COLUMN]).toBe(RUN_AS_TEMPLATE);
    await expect(page.locator('#schedule-approval')).toHaveText(SCHEDULE_NOTE('e2e-approval-rule'));
  });

  test('a schedule of a template gated through its inventory says each run waits for the inventory\'s rule, right after Runs as', async ({ page }) => {
    const cells = await openSchedule(page, HOST_SCHEDULE);
    expect(cells[SCHEDULE_TEMPLATE_COLUMN]).toBe(HOST_TEMPLATE);
    await expect(page.locator('#schedule-approval')).toHaveText(SCHEDULE_NOTE_INVENTORY(HOST_RULE, HOST_INVENTORY));
    const labels = await detailLabels(page);
    expect(labels[labels.indexOf('Runs as') + 1]).toBe('Approval');
  });

  test('a schedule of an ungated template, or of a deleted one, has no Approval row', async ({ page }) => {
    for (const name of ['e2e-schedule', DELETED_TEMPLATE_SCHEDULE]) {
      await openSchedule(page, name);
      await expect(page.locator('#page-content h2').first(), name).toContainText(name);
      await expect(page.locator('#schedule-approval'), name).toHaveCount(0);
      expect(await detailLabels(page), name).not.toContain('Approval');
      expect(await detailLabels(page), name).toContain('Runs as');
    }
  });
});

import { test, expect, Locator, Page } from '@playwright/test';
import { idWhere, indexRows } from '../../lib/scoping';

/**
 * commands/E2eNotificationDeliveriesSeeder.php: a Mattermost template that
 * delivers as IDENTITY, a user who is inactive, so it delivers nothing; it
 * keeps a passed check of its config and has four finished deliveries.
 */
const TEMPLATE = 'e2e-mattermost-deliveries';
const IDENTITY = 'e2e-nt-dormant';
const BOT_USERNAME = 'e2e-bot';
const CHANNEL_ID = 'e2echannelid00000000000001';
const WORKFLOWS = ['e2e-alpha-wf', 'e2e-beta-wf'];

/** services/notification/NotificationTemplatePage.php DELIVERS_AS_INACTIVE, for IDENTITY. */
const DELIVERS_NOTHING = `Delivers nothing: ${IDENTITY}, the user it delivers as, is inactive. Saving the template makes whoever saves it the user it delivers as.`;

/** services/notification/NotificationTemplatePage.php CHECK_KEPT, after its time. */
const CHECK_KEPT = new RegExp(`^\\s*Checked \\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}: the bot @${BOT_USERNAME} may post to channel ${CHANNEL_ID}\\.\\s*$`);

/** services/notification/MattermostApiException.php TOKEN_REFUSED, with the seeder's TOKEN_DETAIL. */
const TOKEN_REFUSED = 'Mattermost refused the bot token (HTTP 401): it was revoked or has expired, or the bot was disabled when its owner was deactivated.';

/** services/notification/NotificationDeliveryService.php INACTIVE_IDENTITY, whatever the user's ID. */
const NOT_SENT_INACTIVE = /^Not sent: the user the template delivers as, #\d+, is inactive\.$/;

/** models/NotificationTemplate.php LEVEL_LABELS[essential] */
const ESSENTIAL = 'Only failures, waits and the end';

/** Opens the page of the notification template `name` (column 0 of the list: Name). */
async function openTemplate(page: Page, name: string): Promise<void> {
  const id = idWhere(await indexRows(page, '/notification-template/index'), 0, name);
  await page.goto(`/notification-template/view?id=${id}`);
  await expect(page.locator('#page-content h2').first()).toHaveText(name);
}

/** The rows of the deliveries table, newest first. */
function deliveryRows(page: Page): Locator {
  return page.locator('#notification-template-deliveries tbody tr');
}

/** The cells Event, Kind, Status, Attempts, About and Last error of a delivery row. */
async function cellsOf(row: Locator): Promise<string[]> {
  const cells = await row.locator('td').allInnerTexts();
  return cells.slice(1, 7).map((cell) => cell.replace(/\s+/g, ' ').trim());
}

/**
 * The page of a notification template (spec answers 3 and 5): whom it
 * delivers as, and a warning when that user is inactive; its workflow
 * filter and level; the check a Mattermost template keeps; its deliveries.
 * What a viewer of another team sees of it: rbac.spec.ts.
 */
test.describe('Notification Template: the page and its deliveries', () => {
  test('the page says whom the template delivers as, warns that the user is inactive, shows its check, and lists its deliveries', async ({ page }) => {
    await openTemplate(page, TEMPLATE);

    await expect(page.getByTestId('notification-template-inactive-identity')).toHaveText(DELIVERS_NOTHING);
    await expect(page.getByTestId('notification-template-delivers-as')).toHaveText(`${IDENTITY} inactive`);
    await expect(page.getByTestId('notification-template-check')).toHaveText(CHECK_KEPT);
    // The check's fingerprint, a SHA-256 in hex, is never shown.
    expect((await page.content()).match(/\b[0-9a-f]{64}\b/g), 'a SHA-256 in the page').toBeNull();
    await expect(page.getByTestId('notification-template-workflows').locator('a')).toHaveText(WORKFLOWS);
    await expect(page.getByTestId('notification-template-hidden-workflows')).toHaveCount(0);
    await expect(page.getByTestId('notification-template-level')).toHaveText(ESSENTIAL);

    const rows = deliveryRows(page);
    await expect(rows).toHaveCount(4);
    const [schedule, waiting, failed, delivered] = [rows.nth(0), rows.nth(1), rows.nth(2), rows.nth(3)];
    const scheduleCells = await cellsOf(schedule);
    expect(scheduleCells.slice(0, 4)).toEqual(['schedule.failed_to_launch', 'Message', 'skipped', '0']);
    expect(scheduleCells[4]).toMatch(/^Job Template #\d+$/);
    expect(scheduleCells[5]).toMatch(NOT_SENT_INACTIVE);
    const waitingCells = await cellsOf(waiting);
    expect(waitingCells.slice(0, 4)).toEqual(['workflow.waiting', 'Status post', 'delivered', '1']);
    expect(waitingCells[4]).toMatch(/^Workflow Job #\d+$/);
    expect(await cellsOf(failed)).toEqual(['runner.recovered', 'Message', 'failed', '8', '', TOKEN_REFUSED]);
    await expect(failed.locator('.badge')).toHaveClass(/text-bg-danger/);
    expect(await cellsOf(delivered)).toEqual(['runner.offline', 'Message', 'delivered', '1', '', '']);
    await expect(page.getByTestId('notification-template-hidden-deliveries')).toHaveCount(0);

    // What a delivery is about links its page: the run that waited.
    await waiting.locator('td').nth(5).locator('a').click();
    await expect(page).toHaveURL(/\/workflow-job\/view\?id=\d+$/);
    await expect(page.locator('#page-content')).toContainText(WORKFLOWS[0]);
  });
});

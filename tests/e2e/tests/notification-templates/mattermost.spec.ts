import { test, expect, Locator, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { submitForm } from '../../lib/helpers';
import { forgePost } from '../../lib/scoping';

/** components/SurveySecrets.php PLACEHOLDER: what the page and the edit form show for a channel secret. */
const PLACEHOLDER = '$encrypted$';

/** models/NotificationTemplate.php HTTPS_REQUIRED, for the key server_url. */
const HTTPS_REQUIRED = 'server_url must be an https URL.';

/** services/notification/MattermostConfig.php MENTION_EVERYONE */
const MENTION_EVERYONE = 'mention may not be @all, @channel or @here, which notify everyone in the channel.';

/** components/NotificationConfigSecrets.php URL_CHANGED, for the bot token after a new server_url. */
const SERVER_URL_CHANGED = 'bot_token: server_url changed; enter the real value.';

/** services/notification/NotificationTemplatePage.php CHECK_NONE */
const CHECK_NONE = 'Not checked yet: Send Test, or the first delivery, checks the bot token and the channel.';

/** services/notification/NotificationTemplatePage.php NO_DELIVERIES */
const NO_DELIVERIES = 'No deliveries yet.';

/** models/NotificationTemplate.php LEVEL_LABELS[essential] */
const ESSENTIAL = 'Only failures, waits and the end';

/** The seeded workflow template (commands/E2eController.php). */
const WORKFLOW = 'e2e-workflow';

/** The workflow events of this release, as the form names their checkboxes: Step started, Step succeeded, Waiting. */
const NEW_EVENTS = ['workflow.step_started', 'workflow.step_succeeded', 'workflow.waiting'];

/** A Mattermost channel ID: 26 lower-case letters and digits. */
const CHANNEL_ID = 'e2echannelidformspec000001';

const VIEW_PAGE = /\/notification-template\/view\?id=(\d+)/;

function eventBox(page: Page, event: string): Locator {
  return page.locator(`input.event-checkbox[id="event-${event}"]`);
}

/** The value of the row `heading` of the open notification template page. */
function row(page: Page, heading: string): Locator {
  return page.locator('#page-content table.table-bordered tr', { has: page.locator('th', { hasText: new RegExp(`^${heading}$`) }) }).locator('td');
}

/** The config the open template page shows, parsed: its keys in any order. */
async function shownConfig(page: Page): Promise<unknown> {
  return JSON.parse((await page.getByTestId('notification-template-config').textContent()) ?? '');
}

/** How often `secret` occurs in the HTML of the open page: its text, attributes and form values. */
async function occurrences(page: Page, secret: string): Promise<number> {
  return (await page.content()).split(secret).length - 1;
}

/** Fills the body template, whose textarea a CodeMirror editor hides. */
async function fillBody(page: Page): Promise<void> {
  await page.locator('textarea[data-template-editor]').evaluate((el) => {
    (el as HTMLTextAreaElement).value = 'E2E {{ event }}';
  });
}

/** The Mattermost panel of the open form, filled; the channel by the names of its team and itself. */
async function fillMattermost(page: Page, server: string, token: string, mention: string): Promise<void> {
  await page.locator('#nt-channel').selectOption('mattermost');
  await expect(page.locator('#config-mattermost')).toBeVisible();
  await page.locator('#mattermost-server-url').fill(server);
  await page.locator('#mattermost-bot-token').fill(token);
  await expect(page.locator('#mattermost-channel-by')).toHaveValue('name');
  await expect(page.locator('#mattermost-channel-id')).toBeHidden();
  await page.locator('#mattermost-team').fill('ops');
  await page.locator('#mattermost-channel').fill('deploys');
  await page.locator('#mattermost-mention').fill(mention);
}

async function deleteTemplate(page: Page, id: string): Promise<void> {
  await page.goto(`/notification-template/view?id=${id}`);
  await forgePost(page, `/notification-template/delete?id=${id}`, {}, /\/notification-template\/index/);
}

/** How many rows of the template list have `name` in their first column. */
async function listedAs(page: Page, name: string): Promise<number> {
  await page.goto('/notification-template/index');
  return page.locator('#page-content table.table tbody tr td:first-child', { hasText: name }).count();
}

/**
 * The Mattermost channel of a notification template (spec section 5): a
 * server URL (https only), a bot token, the channel by its team's and its
 * own name or by its ID, and a mention. Saving asks Mattermost nothing, so
 * no spec reaches a Mattermost server. The template's workflow filter and
 * level, and the workflow events of this release.
 */
test.describe('Notification Template: Mattermost', () => {
  test('admin creates a Mattermost template with a server, a bot token, a team and a channel, a mention, a workflow filter and a level', async ({ page }) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-nt-mattermost-${suffix}`;
    // A fixture, not a secret: what no page may show.
    const token = `e2e-mattermost-token-${suffix}`;

    await page.goto('/notification-template/create');
    await page.locator('#notificationtemplate-name').fill(name);
    await fillMattermost(page, 'https://chat.example.com', token, '@ops-team');
    await page.locator('#nt-preset-none').click();
    for (const event of [...NEW_EVENTS, 'workflow.failed']) {
      await eventBox(page, event).check();
    }
    // The workflows: the multi-select shows once "Only these workflows" is chosen.
    await expect(page.locator('#nt-workflow-scope-all')).toBeChecked();
    await expect(page.locator('#nt-workflow-templates')).toBeHidden();
    await page.locator('#nt-workflow-scope-selected').check();
    await expect(page.locator('#nt-workflow-templates')).toBeVisible();
    await page.locator('#nt-workflow-templates').selectOption({ label: WORKFLOW });
    await expect(page.locator('#nt-level-all')).toBeChecked();
    await page.locator('#nt-level-essential').check();
    await fillBody(page);
    await submitForm(page);
    await expect(flash(page, 'success')).toContainText(`Notification template "${name}" created.`);
    const id = page.url().match(VIEW_PAGE)?.[1] ?? '';
    expect(id, 'the template page opens').toBeTruthy();

    try {
      expect(await shownConfig(page)).toEqual({ server_url: 'https://chat.example.com', bot_token: PLACEHOLDER, team: 'ops', channel: 'deploys', mention: '@ops-team' });
      expect(await occurrences(page, token), 'the bot token in the page').toBe(0);
      await expect(row(page, 'Channel')).toHaveText('Mattermost');
      await expect(row(page, 'Events').locator('.badge')).toHaveText(['workflow.failed', ...NEW_EVENTS]);
      await expect(page.getByTestId('notification-template-workflows')).toHaveText(WORKFLOW);
      await expect(page.getByTestId('notification-template-level')).toHaveText(ESSENTIAL);
      await expect(page.getByTestId('notification-template-delivers-as')).toHaveText('e2e-admin');
      await expect(page.getByTestId('notification-template-inactive-identity')).toHaveCount(0);
      await expect(page.getByTestId('notification-template-check')).toHaveText(CHECK_NONE);
      await expect(page.getByTestId('notification-template-no-deliveries')).toHaveText(NO_DELIVERIES);

      // The edit form shows it as saved, the bot token as the placeholder.
      await page.locator('#page-content a:has-text("Edit")').click();
      await expect(page.locator('#config-mattermost')).toBeVisible();
      await expect(page.locator('#mattermost-server-url')).toHaveValue('https://chat.example.com');
      await expect(page.locator('#mattermost-bot-token')).toHaveValue(PLACEHOLDER);
      await expect(page.locator('#mattermost-team')).toHaveValue('ops');
      await expect(page.locator('#mattermost-channel')).toHaveValue('deploys');
      await expect(page.locator('#mattermost-mention')).toHaveValue('@ops-team');
      for (const event of NEW_EVENTS) {
        await expect(eventBox(page, event)).toBeChecked();
      }
      await expect(page.locator('#nt-workflow-scope-selected')).toBeChecked();
      await expect(page.locator('#nt-workflow-templates option:checked')).toHaveText([WORKFLOW]);
      await expect(page.locator('#nt-level-essential')).toBeChecked();
      await expect(page.getByTestId('notification-template-delivers-as-hint')).toContainText('Delivers as e2e-admin');
      expect(await occurrences(page, token), 'the bot token in the edit form').toBe(0);

      // Another server with the stored token: refused, so the token never goes there.
      await page.locator('#mattermost-server-url').fill('https://other.example.com');
      await submitForm(page);
      await expect(page.locator('.field-nt-config')).toContainText(SERVER_URL_CHANGED);
      expect(await occurrences(page, token), 'the bot token after the refusal').toBe(0);

      // The channel by its ID, on the server as it was: the stored token stays.
      await page.locator('#mattermost-server-url').fill('https://chat.example.com');
      await page.locator('#mattermost-channel-by').selectOption('id');
      await expect(page.locator('#mattermost-team')).toBeHidden();
      await page.locator('#mattermost-channel-id').fill(CHANNEL_ID);
      await submitForm(page);
      await expect(flash(page, 'success')).toContainText(`Notification template "${name}" updated.`);
      expect(await shownConfig(page)).toEqual({ server_url: 'https://chat.example.com', bot_token: PLACEHOLDER, channel_id: CHANNEL_ID, mention: '@ops-team' });

      await page.locator('#page-content a:has-text("Edit")').click();
      await expect(page.locator('#mattermost-channel-by')).toHaveValue('id');
      await expect(page.locator('#mattermost-channel-id')).toHaveValue(CHANNEL_ID);
    } finally {
      await deleteTemplate(page, id);
    }
  });

  test('a server URL that is no https URL and an @all mention are refused, both at once, and nothing is stored', async ({ page }) => {
    const name = `e2e-nt-mattermost-refused-${Date.now()}`;
    await page.goto('/notification-template/create');
    await page.locator('#notificationtemplate-name').fill(name);
    await fillMattermost(page, 'http://chat.example.com', 'e2e-mattermost-refused-token', '@all');
    await eventBox(page, 'workflow.failed').check();
    await fillBody(page);
    await submitForm(page);

    const errors = page.locator('.field-nt-config .help-block');
    await expect(errors).toContainText(HTTPS_REQUIRED);
    await expect(errors).toContainText(MENTION_EVERYONE);
    await expect(flash(page, 'success')).toHaveCount(0);
    // The form keeps what was typed.
    await expect(page.locator('#mattermost-server-url')).toHaveValue('http://chat.example.com');
    await expect(page.locator('#mattermost-mention')).toHaveValue('@all');

    await page.locator('#mattermost-mention').fill('@everyone-but-all');
    await submitForm(page);
    await expect(errors).toContainText(HTTPS_REQUIRED);
    await expect(errors).not.toContainText(MENTION_EVERYONE);
    expect(await listedAs(page, name), 'the template was not created').toBe(0);
  });
});

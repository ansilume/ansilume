import { test, expect, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { expectForbidden, submitForm } from '../../lib/helpers';
import { asAdmin, forgePost, idWhere, indexRows } from '../../lib/scoping';

/** components/SurveySecrets.php PLACEHOLDER: what the page shows for a channel secret. */
const PLACEHOLDER = '$encrypted$';

/** components/NotificationConfigSecrets.php PAGE_NOTE */
const PAGE_NOTE = `Channel secrets show as ${PLACEHOLDER}.`;

const VIEW_PAGE = /\/notification-template\/view\?id=(\d+)/;

/**
 * commands/E2eNotificationDeliveriesSeeder.php: a Mattermost template whose
 * filter holds e2e-alpha-wf, which e2e-operator's team may see, and
 * e2e-beta-wf, which e2e-viewer's team may see; of its four deliveries,
 * e2e-viewer may see the two of runners: the waiting run of e2e-alpha-wf
 * and the schedule event (job.launch) are hidden from them.
 */
const SEEDED_TEMPLATE = 'e2e-mattermost-deliveries';
const ALPHA_WORKFLOW = 'e2e-alpha-wf';
const BETA_WORKFLOW = 'e2e-beta-wf';

/** services/notification/NotificationTemplatePage.php HIDDEN_WORKFLOWS and HIDDEN_DELIVERIES */
function hiddenWorkflows(count: number): string {
  return `+ ${count} workflow template(s) you cannot see`;
}
function hiddenDeliveries(count: number): string {
  return `+ ${count} delivery(ies) you cannot see`;
}

/** services/notification/NotificationTemplateFormView.php HIDDEN_WORKFLOWS */
function hiddenInTheForm(count: number): string {
  return `+ ${count} workflow template(s) you cannot see, which stay selected`;
}

/** The ID of the notification template `name`, from the list (column 0: Name). */
async function templateIdOf(page: Page, name: string): Promise<string> {
  return idWhere(await indexRows(page, '/notification-template/index'), 0, name);
}

/** Fills the body template, whose textarea a CodeMirror editor hides. */
async function fillBody(page: Page): Promise<void> {
  await page.locator('textarea[data-template-editor]').evaluate((el) => {
    (el as HTMLTextAreaElement).value = 'E2E {{ event }}';
  });
}

/**
 * Creates a Telegram notification template with `botToken` through a real
 * form post of the open (admin) page; returns its id. A test fixture, not a
 * secret.
 */
async function createTelegramTemplate(admin: Page, name: string, botToken: string): Promise<string> {
  await admin.goto('/notification-template/index');
  await forgePost(admin, '/notification-template/create', {
    'NotificationTemplate[name]': name,
    'NotificationTemplate[channel]': 'telegram',
    'NotificationTemplate[config]': JSON.stringify({ bot_token: botToken, chat_id: '-100200300' }),
    'NotificationTemplate[events]': 'job.failed',
    'NotificationTemplate[subject_template]': 'E2E {{ event }}',
    'NotificationTemplate[body_template]': 'E2E {{ event }}',
  }, VIEW_PAGE);
  const id = admin.url().match(VIEW_PAGE)?.[1];
  expect(id, 'the new template opens').toBeTruthy();
  return id ?? '';
}

async function deleteTemplate(admin: Page, id: string): Promise<void> {
  await admin.goto(`/notification-template/view?id=${id}`);
  await forgePost(admin, `/notification-template/delete?id=${id}`, {}, /\/notification-template\/index/);
}

/** The "Created" row of the open notification template page: "<date> by <username>". */
function createdRow(page: Page) {
  return page.locator('#page-content tr', { has: page.locator('th', { hasText: /^Created$/ }) }).locator('td');
}

test.describe('Notification Templates RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/notification-template/create');
    await expectForbidden(page);
  });

  test('viewer can view index', async ({ page }) => {
    await page.goto('/notification-template/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator can create notification templates', async ({ page }) => {
    await page.goto('/notification-template/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator creates a Mattermost template, which delivers as e2e-operator', async ({ page, browser }, testInfo) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-nt-operator-mattermost-${suffix}`;
    await page.goto('/notification-template/create');
    await page.locator('#notificationtemplate-name').fill(name);
    await page.locator('#nt-channel').selectOption('mattermost');
    await page.locator('#mattermost-server-url').fill('https://chat.example.com');
    // A fixture, not a secret.
    await page.locator('#mattermost-bot-token').fill(`e2e-operator-token-${suffix}`);
    await page.locator('#mattermost-channel-by').selectOption('id');
    await page.locator('#mattermost-channel-id').fill('e2echanneloperatorspec0001');
    await page.locator('input.event-checkbox[id="event-workflow.waiting"]').check();
    await fillBody(page);
    await submitForm(page);
    await expect(flash(page, 'success')).toContainText(`Notification template "${name}" created.`);
    const id = page.url().match(VIEW_PAGE)?.[1] ?? '';
    expect(id, 'the new template opens').toBeTruthy();
    try {
      await expect(page.getByTestId('notification-template-delivers-as')).toHaveText('e2e-operator');
      await expect(page.getByTestId('notification-template-inactive-identity')).toHaveCount(0);
      // An operator may change and test it, but not delete it.
      await expect(page.locator('#page-content a:has-text("Edit")')).toHaveCount(1);
      await expect(page.locator('#page-content form[action*="/notification-template/delete"]')).toHaveCount(0);
    } finally {
      await asAdmin(browser, testInfo, (admin) => deleteTemplate(admin, id));
    }
  });

  // Spec answer 5: an editor sets only the workflows they may see; the others
  // stay, counted, never named.
  test('operator keeps the workflows of a filter that they cannot see when they save the template', async ({ page, browser }, testInfo) => {
    const name = `e2e-nt-operator-filter-${Date.now()}`;
    const id = await asAdmin(browser, testInfo, async (admin) => {
      await admin.goto('/notification-template/create');
      await admin.locator('#notificationtemplate-name').fill(name);
      await admin.locator('#nt-channel').selectOption('email');
      await admin.locator('#email-recipients').fill('["e2e@example.com"]');
      await admin.locator('#nt-workflow-scope-selected').check();
      await admin.locator('#nt-workflow-templates').selectOption([{ label: ALPHA_WORKFLOW }, { label: BETA_WORKFLOW }]);
      await fillBody(admin);
      await submitForm(admin);
      await expect(flash(admin, 'success')).toContainText(`Notification template "${name}" created.`);
      await expect(admin.getByTestId('notification-template-workflows').locator('a')).toHaveText([ALPHA_WORKFLOW, BETA_WORKFLOW]);
      return admin.url().match(VIEW_PAGE)?.[1] ?? '';
    });
    expect(id, 'the template page opened').toBeTruthy();
    try {
      await page.goto(`/notification-template/update?id=${id}`);
      const options = page.locator('#nt-workflow-templates option');
      await expect(options.filter({ hasText: new RegExp(`^${ALPHA_WORKFLOW}$`) })).toHaveCount(1);
      await expect(options.filter({ hasText: new RegExp(`^${BETA_WORKFLOW}$`) })).toHaveCount(0);
      await expect(page.locator('#nt-workflow-templates option:checked')).toHaveText([ALPHA_WORKFLOW]);
      await expect(page.getByTestId('nt-workflow-templates-hidden')).toHaveText(hiddenInTheForm(1));
      expect((await page.content()).includes(BETA_WORKFLOW), 'the hidden workflow is not named').toBe(false);

      await page.locator('#notificationtemplate-description').fill('saved by the operator');
      await submitForm(page);
      await expect(flash(page, 'success')).toContainText(`Notification template "${name}" updated.`);
      await expect(page.getByTestId('notification-template-workflows').locator('a')).toHaveText([ALPHA_WORKFLOW]);
      await expect(page.getByTestId('notification-template-hidden-workflows')).toHaveText(hiddenWorkflows(1));
      // A change of the description changes neither where nor what it delivers.
      await expect(page.getByTestId('notification-template-delivers-as')).toHaveText('e2e-admin');

      await asAdmin(browser, testInfo, async (admin) => {
        await admin.goto(`/notification-template/view?id=${id}`);
        await expect(admin.getByTestId('notification-template-workflows').locator('a')).toHaveText([ALPHA_WORKFLOW, BETA_WORKFLOW]);
      });
    } finally {
      await asAdmin(browser, testInfo, (admin) => deleteTemplate(admin, id));
    }
  });

  test('viewer gets 403 on update and is offered no change of a template', async ({ page }) => {
    const id = await templateIdOf(page, SEEDED_TEMPLATE);
    const response = await page.goto(`/notification-template/update?id=${id}`);
    expect(response?.status(), 'the edit form').toBe(403);
    await expectForbidden(page);

    await page.goto('/notification-template/index');
    await expect(page.locator('#page-content a:has-text("New Notification Template")')).toHaveCount(0);
    await page.goto(`/notification-template/view?id=${id}`);
    await expect(page.locator('#page-content a:has-text("Edit")')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/notification-template/test"]')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/notification-template/delete"]')).toHaveCount(0);
  });

  // Spec answer 5: the page shows a viewer only what they may see, and counts the rest.
  test('viewer sees the workflows and the deliveries of a template that they may see, and only counts the others', async ({ page }) => {
    await page.goto(`/notification-template/view?id=${await templateIdOf(page, SEEDED_TEMPLATE)}`);

    await expect(page.getByTestId('notification-template-workflows').locator('a')).toHaveText([BETA_WORKFLOW]);
    await expect(page.getByTestId('notification-template-hidden-workflows')).toHaveText(hiddenWorkflows(1));
    const events = page.locator('#notification-template-deliveries tbody tr td:nth-child(2)');
    await expect(events).toHaveText(['runner.recovered', 'runner.offline']);
    await expect(page.getByTestId('notification-template-hidden-deliveries')).toHaveText(hiddenDeliveries(2));
    expect((await page.content()).includes(ALPHA_WORKFLOW), 'the hidden workflow is not named').toBe(false);
  });

  // Regression: the edit form took created_by from the post, so whoever may
  // edit a template could name any user, an admin included, as its creator.
  test('operator cannot name another user as the creator of a template', async ({ page, browser }, testInfo) => {
    const adminId = await asAdmin(browser, testInfo, async (admin) => idWhere(await indexRows(admin, '/user/index'), 1, 'e2e-admin'));
    const name = `e2e-nt-created-by-${Date.now()}`;
    const fields = {
      'NotificationTemplate[name]': name,
      'NotificationTemplate[channel]': 'email',
      'NotificationTemplate[config]': JSON.stringify({ emails: ['e2e@example.com'] }),
      'NotificationTemplate[events]': 'job.failed',
      'NotificationTemplate[subject_template]': 'E2E {{ event }}',
      'NotificationTemplate[body_template]': 'E2E {{ event }}',
      'NotificationTemplate[created_by]': adminId,
    };
    await page.goto('/notification-template/index');
    await forgePost(page, '/notification-template/create', fields, VIEW_PAGE);
    const id = page.url().match(VIEW_PAGE)?.[1] ?? '';
    expect(id, 'the new template opens').toBeTruthy();
    try {
      await expect(createdRow(page)).toHaveText(/ by e2e-operator$/);

      await page.goto('/notification-template/index');
      await forgePost(page, `/notification-template/update?id=${id}`, { ...fields, 'NotificationTemplate[description]': 'renamed by the operator' }, VIEW_PAGE);
      await expect(page.locator('#page-content')).toContainText('renamed by the operator');
      await expect(createdRow(page)).toHaveText(/ by e2e-operator$/);
    } finally {
      await asAdmin(browser, testInfo, (admin) => deleteTemplate(admin, id));
    }
  });

  // Regression: every signed-in user holds the default role viewer, and the
  // template page printed the stored channel config: a viewer read the bot
  // token (and every webhook URL, header token and routing key).
  test('viewer sees the placeholder, never the stored bot token, on a template page', async ({ page, browser }, testInfo) => {
    const suffix = `${Date.now()}`;
    const name = `e2e-nt-secret-viewer-${suffix}`;
    const botToken = `424242:AAE-e2e-viewer-must-not-see-${suffix}`;
    const id = await asAdmin(browser, testInfo, (admin) => createTelegramTemplate(admin, name, botToken));
    try {
      await page.goto(`/notification-template/view?id=${id}`);
      await expect(page.locator('#page-content h2').first()).toHaveText(name);

      await expect(page.getByTestId('notification-template-config')).toHaveText(`{"bot_token":"${PLACEHOLDER}","chat_id":"-100200300"}`);
      await expect(page.getByTestId('notification-template-secret-note')).toHaveText(PAGE_NOTE);
      const html = await page.content();
      expect(html.split(botToken).length - 1, 'occurrences of the bot token in the page').toBe(0);
      // A viewer may not edit or test the template either.
      await expect(page.locator('#page-content a:has-text("Edit")')).toHaveCount(0);
    } finally {
      await asAdmin(browser, testInfo, (admin) => deleteTemplate(admin, id));
    }
  });
});

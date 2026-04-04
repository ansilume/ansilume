import { test as setup } from '@playwright/test';
import { LOGIN_USERNAME, LOGIN_PASSWORD, LOGIN_SUBMIT } from '../lib/selectors';

const users = [
  { role: 'admin', username: 'e2e-admin', password: 'E2eAdminPass1!' },
  { role: 'operator', username: 'e2e-operator', password: 'E2eOperatorPass1!' },
  { role: 'viewer', username: 'e2e-viewer', password: 'E2eViewerPass1!' },
];

for (const { role, username, password } of users) {
  setup(`authenticate as ${role}`, async ({ page }) => {
    await page.goto('/site/login');
    await page.locator(LOGIN_USERNAME).fill(username);
    await page.locator(LOGIN_PASSWORD).fill(password);
    await page.locator(LOGIN_SUBMIT).click();
    // Wait for redirect to dashboard
    await page.waitForURL('**/site/index', { timeout: 10_000 }).catch(() => {
      // Some setups redirect to / instead
      return page.waitForURL('**/', { timeout: 5_000 });
    });
    await page.context().storageState({ path: `.auth/${role}.json` });
  });
}

import { APIResponse, expect, Page } from '@playwright/test';
import { idWhere, indexRows } from './scoping';
import { FLASH_SUCCESS, FLASH_DANGER, FORM_SUBMIT } from './selectors';

/** The flash types the layout prints (components/FlashAlerts.php). */
export type FlashType = 'success' | 'danger' | 'warning' | 'info';

/**
 * The warning and info flashes. The layout prints every flash as a
 * dismissible alert (role=alert) right in #page-content; the run page's
 * banner and the job page's notes are warning and info alerts there too,
 * but no flashes, so a flash matcher must require the dismissible class.
 * A page can show several flashes of one type: each warning of a step
 * change is a flash of its own.
 */
const NOTICE_FLASHES: Record<'warning' | 'info', string> = {
  warning: '#page-content > .alert-warning.alert-dismissible',
  info: '#page-content > .alert-info.alert-dismissible',
};

/**
 * Assert a flash message of the given type contains the expected text: the
 * first success or danger flash; for a warning or info flash, any flash of
 * that type, as a page can show several.
 */
export async function expectFlash(page: Page, type: FlashType, text?: string) {
  if (type === 'warning' || type === 'info') {
    const flashes = page.locator(NOTICE_FLASHES[type]);
    const flash = text ? flashes.filter({ hasText: text }).first() : flashes.first();
    await expect(flash, `a ${type} flash${text ? `: ${text}` : ''}`).toBeVisible({ timeout: 5_000 });
    return;
  }
  const selector = type === 'success' ? FLASH_SUCCESS : FLASH_DANGER;
  const flash = page.locator(selector).first();
  await expect(flash).toBeVisible({ timeout: 5_000 });
  if (text) {
    await expect(flash).toContainText(text);
  }
}

/**
 * Click the form submit button and wait for navigation.
 */
export async function submitForm(page: Page, buttonText?: string) {
  const btn = buttonText
    ? page.locator(`button:has-text("${buttonText}"), input[type="submit"][value="${buttonText}"]`).first()
    : page.locator(FORM_SUBMIT).first();
  // Wait for the post-submit navigation so the flash-bearing GET has rendered
  // before the test asserts. `waitForLoadState('networkidle')` alone can
  // resolve on the pre-click state and skip the navigation entirely.
  await Promise.all([
    page.waitForNavigation({ timeout: 10_000 }).catch(() => {}),
    btn.click(),
  ]);
}

/**
 * Fill a Yii2 ActiveForm given field name/value pairs.
 * Keys are the field name attribute suffixes (e.g. 'name', 'description').
 */
export async function fillForm(page: Page, model: string, fields: Record<string, string>) {
  for (const [field, value] of Object.entries(fields)) {
    const selector = `#${model}-${field}`;
    const el = page.locator(selector);
    const tag = await el.evaluate((e) => e.tagName.toLowerCase());
    if (tag === 'select') {
      await el.selectOption(value);
    } else if (tag === 'textarea') {
      await el.fill(value);
    } else {
      await el.fill(value);
    }
  }
}

/**
 * POSTs `fields` to `path` with the session and CSRF token of the open page,
 * as its forms do, and returns the response without following a redirect:
 * for asserting the status code of a refused action.
 */
export async function postWithPageCsrf(page: Page, path: string, fields: Record<string, string> = {}): Promise<APIResponse> {
  const csrf = await page.evaluate(() => ({
    // The layout names only the token; the parameter is Yii's default.
    param: document.querySelector('meta[name="csrf-param"]')?.getAttribute('content') || '_csrf',
    token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
  }));
  expect(csrf.token, 'the page has a CSRF token').not.toBe('');
  return page.request.post(path, { form: { [csrf.param]: csrf.token, ...fields }, maxRedirects: 0 });
}

/** The id of a job template, from the template filter of the job list. */
export async function templateId(page: Page, name: string): Promise<string> {
  await page.goto('/job/index');
  const ids = await page.locator('select[name="template_id"] option').evaluateAll(
    (options, wanted) => options
      .filter((option) => (option.textContent ?? '').trim() === wanted)
      .map((option) => (option as HTMLOptionElement).value),
    name,
  );
  expect(ids, `exactly one job template named ${name}`).toHaveLength(1);
  return ids[0];
}

/** The id of the user `username`, from the user list (columns: #, Username, ...); needs user.view. */
export async function userId(page: Page, username: string): Promise<string> {
  return idWhere(await indexRows(page, '/user/index'), 1, username);
}

/** The newest job of the template in `status`, from the job list. */
export async function newestJobId(page: Page, template: string, status: string): Promise<string> {
  await page.goto(`/job/index?template_id=${template}&status=${status}`);
  const href = await page.locator('#page-content table.table tbody tr a').first().getAttribute('href');
  const id = href?.match(/[?&]id=(\d+)/)?.[1];
  expect(id, `a ${status} job of job template #${template}`).toBeTruthy();
  return id ?? '';
}

/**
 * Assert the page shows a 403 Forbidden response.
 */
export async function expectForbidden(page: Page) {
  await expect(page.locator('body')).toContainText(/\bForbidden\b/i, { timeout: 5_000 });
}

/**
 * Count rows in a GridView table.
 */
export async function getTableRowCount(page: Page): Promise<number> {
  return page.locator('table.table tbody tr').count();
}

/**
 * Navigate to a section via the sidebar.
 */
export async function navigateTo(page: Page, text: string) {
  await page.locator(`#sidebar a:has-text("${text}")`).first().click();
  await page.waitForLoadState('networkidle');
}

/**
 * Delete an entity whose row contains the given text.
 * Most Ansilume resources expose Delete only on the view page, not the index.
 * Flow: navigate to index → click row link → auto-accept confirm → click Delete.
 */
export async function deleteByRowText(page: Page, indexUrl: string, rowText: string) {
  page.on('dialog', (d) => d.accept());
  await page.goto(indexUrl);
  const row = page.locator('table.table tbody tr', { hasText: rowText }).first();
  await expect(row).toBeVisible({ timeout: 5_000 });
  // Some entities (e.g. runner-group) expose Delete inline on the index row.
  // Try that first — submit the row's delete form directly via JS.
  const inlineSubmitted = await row.evaluate((tr) => {
    const form = Array.from(tr.querySelectorAll('form'))
      .find((f) => /delete/i.test(f.textContent || ''));
    if (form) {
      (form as HTMLFormElement).submit();
      return true;
    }
    return false;
  });
  if (inlineSubmitted) {
    await page.waitForLoadState('networkidle', { timeout: 10_000 });
    return;
  }
  await row.locator('a').first().click();
  // The Delete control is a <button type="submit"> inside a <form> with
  // explicit CSRF token. The data-method=post anchor fallback is kept for
  // backwards compatibility but should no longer be needed.
  await page.waitForLoadState('domcontentloaded');
  const submitted = await page.evaluate(() => {
    const root = document.getElementById('page-content') || document.body;
    // Prefer an explicit form with a Delete submit button.
    const btn = Array.from(root.querySelectorAll('form button[type="submit"], form input[type="submit"]'))
      .find((el) => /delete/i.test((el as HTMLElement).innerText || (el as HTMLInputElement).value || ''));
    if (btn) {
      const form = (btn as HTMLButtonElement).closest('form') as HTMLFormElement | null;
      if (form) {
        form.submit();
        return true;
      }
    }
    // Fall back to Yii data-method=post anchor.
    const link = Array.from(root.querySelectorAll('a[data-method="post"]'))
      .find((el) => /delete/i.test((el as HTMLElement).innerText || ''));
    if (link) {
      const href = (link as HTMLAnchorElement).getAttribute('href') || '';
      const csrfMeta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
      const csrfParam = (document.querySelector('meta[name="csrf-param"]') as HTMLMetaElement | null)?.content || '_csrf';
      const form = document.createElement('form');
      form.method = 'post';
      form.action = href;
      if (csrfMeta) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = csrfParam;
        input.value = csrfMeta.content;
        form.appendChild(input);
      }
      document.body.appendChild(form);
      form.submit();
      return true;
    }
    return false;
  });
  if (!submitted) throw new Error('No Delete button/link found on view page');
  await page.waitForLoadState('networkidle', { timeout: 10_000 });
}

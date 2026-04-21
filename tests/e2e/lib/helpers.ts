import { expect, Page } from '@playwright/test';
import { FLASH_SUCCESS, FLASH_DANGER, FORM_SUBMIT } from './selectors';

/**
 * Assert a flash message of the given type contains the expected text.
 */
export async function expectFlash(page: Page, type: 'success' | 'danger', text?: string) {
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
 * Assert the page shows a 403 Forbidden response.
 */
export async function expectForbidden(page: Page) {
  await expect(page.locator('body')).toContainText(/\b403\b|\bForbidden\b/i, { timeout: 5_000 });
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

import { Browser, BrowserContext, Page, TestInfo, expect } from '@playwright/test';
import * as path from 'path';

/**
 * Helpers for the team scoping specs (tests/team-scoping/). Fixtures come from
 * commands/E2eTeamScopingSeeder.php and commands/E2eWorkflowScopingSeeder.php.
 */

/** The admin session saved by auth.setup.ts. */
const ADMIN_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json');

/** One row of an index table: the id of its first link, the text of every cell, the actions of its forms. */
export interface IndexRow {
  id: string;
  cells: string[];
  forms: string[];
}

/**
 * Every row of a paginated index table. Yii answers a page number past the
 * end with the last page, so reading stops when a page repeats the previous
 * one (or has no rows).
 */
export async function indexRows(page: Page, indexPath: string, maxPages = 30): Promise<IndexRow[]> {
  const rows: IndexRow[] = [];
  let previousFirstId = '';
  for (let pageNo = 1; pageNo <= maxPages; pageNo++) {
    const separator = indexPath.includes('?') ? '&' : '?';
    await page.goto(`${indexPath}${separator}page=${pageNo}`);
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    const pageRows = await page.locator('#page-content table.table tbody tr').evaluateAll((trs) => trs.map((tr) => {
      const href = tr.querySelector('a')?.getAttribute('href') ?? '';
      const match = href.match(/[?&]id=(\d+)/);
      return {
        id: match ? match[1] : '',
        cells: Array.from(tr.querySelectorAll('td')).map((td) => (td.textContent ?? '').replace(/\s+/g, ' ').trim()),
        forms: Array.from(tr.querySelectorAll('form')).map((form) => form.getAttribute('action') ?? ''),
      };
    }));
    if (pageRows.length === 0 || pageRows[0].id === previousFirstId) {
      break;
    }
    previousFirstId = pageRows[0].id;
    rows.push(...pageRows);
  }
  return rows;
}

/** The rows whose cell number `column` (0-based) is exactly `text`. */
export function rowsWhere(rows: IndexRow[], column: number, text: string): IndexRow[] {
  return rows.filter((row) => row.cells[column] === text);
}

/** The id of the only row whose cell number `column` is exactly `text`. */
export function idWhere(rows: IndexRow[], column: number, text: string): string {
  const matches = rowsWhere(rows, column, text);
  expect(matches, `exactly one row with "${text}" in column ${column}`).toHaveLength(1);
  return matches[0].id;
}

/**
 * Runs `use` with a page signed in as e2e-admin, for looking up the ids of
 * fixtures the role under test may not see, and for checking that a refused
 * request changed nothing. The admin specs share this session; tests run one
 * at a time (workers: 1).
 */
export async function asAdmin<T>(browser: Browser, testInfo: TestInfo, use: (admin: Page) => Promise<T>): Promise<T> {
  const context: BrowserContext = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: ADMIN_STATE,
  });
  try {
    return await use(await context.newPage());
  } finally {
    await context.close();
  }
}

/**
 * Submits a hidden form from the current page: a real browser POST with the
 * page's valid CSRF token, so only the server-side checks can refuse it. The
 * current URL must not match `landsOn`, the URL the response is expected at.
 */
export async function forgePost(page: Page, action: string, fields: Record<string, string>, landsOn: RegExp): Promise<void> {
  expect(page.url(), 'the forged form starts on another URL than it lands on').not.toMatch(landsOn);
  await Promise.all([
    page.waitForURL(landsOn),
    page.evaluate(({ target, values }) => {
      const form = document.createElement('form');
      form.method = 'post';
      form.action = target;
      const add = (name: string, value: string) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      };
      add(
        (document.querySelector('meta[name="csrf-param"]') as HTMLMetaElement | null)?.content || '_csrf',
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement).content,
      );
      for (const [name, value] of Object.entries(values)) {
        add(name, value);
      }
      document.body.appendChild(form);
      form.submit();
    }, { target: action, values: fields }),
  ]);
}

/** The option labels of a select, trimmed. */
export async function optionLabels(page: Page, selector: string): Promise<string[]> {
  return page.locator(selector).locator('option').evaluateAll((options) => options.map((o) => (o.textContent ?? '').trim()));
}

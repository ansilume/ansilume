import { test, expect, Page } from '@playwright/test';
import { templateId } from '../../lib/helpers';
import { UNREACHABLE_JOB, UNREACHABLE_TASK } from '../../lib/runs';

/**
 * regression: badges the dark theme hid. The layout always renders
 * data-bs-theme="dark", whose page background is the colour of
 * text-bg-dark: a "dark" badge kept only its text, and muted text on a light
 * badge was light grey on near-white. Before 2.11.0 (git show HEAD:...):
 * the unreachable task badge (models/JobTask.php statusCssClass() 'dark'),
 * the job list's unreachable recap badge (views/job/index.php text-bg-dark),
 * the canceled approval request badge (models/ApprovalRequest.php 'dark'),
 * the webhook list's "No" badge (views/webhook/index.php text-bg-light
 * text-muted) and the dashboard's unreachable bars (views/site/index.php
 * '#343a40'). tests/unit/views/DarkThemeColoursTest.php and
 * tests/unit/models/ApprovalRequestTest.php guard the classes; this measures
 * what the browser draws.
 *
 * Every visible badge must stand out from what is behind it (contrast at
 * least 1.5) and its text from the badge (at least 3, WCAG for large text),
 * on the job list, a job page with an unreachable task, the webhook list,
 * the approval list with a canceled request, and the dashboard, whose chart
 * colours must stand out from the chart's card too.
 *
 * Fixture: the failed job of e2e-template whose one host was unreachable,
 * marked by its execution command e2e-unreachable-job
 * (commands/E2eWorkflowWaitSeeder.php).
 */
const MIN_BACKGROUND_CONTRAST = 1.5;
const MIN_TEXT_CONTRAST = 3;

/** A badge as the browser draws it: its text and its two contrasts. */
interface Badge {
  text: string;
  /** The contrast of the badge's colour with the background behind it. */
  background: number;
  /** The contrast of the badge's text with the badge. */
  foreground: number;
}

/** The colour helpers installColourHelpers() puts on the page, as window.e2eColours. */
interface Colours {
  /** An rgb() or rgba() colour as [red, green, blue, alpha]. */
  parse: (colour: string) => number[];
  /** `top` drawn over the opaque `bottom`. */
  over: (top: number[], bottom: number[]) => number[];
  /** The opaque colour an element's background is drawn on: its own and its ancestors' backgrounds, composed. */
  behind: (el: Element) => number[];
  /** The WCAG contrast ratio of two opaque colours. */
  contrast: (a: number[], b: number[]) => number;
}

/**
 * Puts the colour helpers on the open page (window.e2eColours): the computed
 * colours are only there. Again after every navigation.
 */
async function installColourHelpers(page: Page): Promise<void> {
  await page.evaluate(() => {
    const parse = (colour: string): number[] => {
      const m = String(colour).match(/rgba?\(([^)]+)\)/);
      if (m === null) {
        return [0, 0, 0, 0];
      }
      const p = m[1].split(',').map((v) => parseFloat(v));
      return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
    };
    const over = (top: number[], bottom: number[]): number[] => [0, 1, 2].map((i) => top[i] * top[3] + bottom[i] * (1 - top[3])).concat([1]);
    const behind = (el: Element): number[] => {
      const layers: number[][] = [];
      for (let node: Element | null = el; node !== null; node = node.parentElement) {
        const c = parse(getComputedStyle(node).backgroundColor);
        if (c[3] > 0) {
          layers.push(c);
        }
        if (c[3] >= 1) {
          break;
        }
      }
      return layers.reduceRight((result, layer) => over(layer, result), [0, 0, 0, 1]);
    };
    const luminance = (c: number[]): number => {
      const v = [0, 1, 2].map((i) => {
        const s = c[i] / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
      });
      return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    };
    const contrast = (a: number[], b: number[]): number => {
      const [la, lb] = [luminance(a), luminance(b)];
      return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    };
    (window as unknown as { e2eColours: Colours }).e2eColours = { parse, over, behind, contrast };
  });
}

/** Every visible badge of the open page, outside the debug toolbar of a dev stack. */
async function badgesOf(page: Page): Promise<Badge[]> {
  await installColourHelpers(page);
  return page.evaluate(() => {
    const colours = (window as unknown as { e2eColours: Colours }).e2eColours;
    return Array.from(document.querySelectorAll('.badge'))
      .filter((el) => el.closest('#yii-debug-toolbar') === null)
      .filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden')
      .map((el) => {
        const back = colours.behind(el.parentElement as Element);
        const style = getComputedStyle(el);
        const fill = colours.over(colours.parse(style.backgroundColor), back);
        const ink = colours.over(colours.parse(style.color), fill);
        return {
          text: (el.textContent ?? '').replace(/\s+/g, ' ').trim(),
          background: Math.round(colours.contrast(fill, back) * 100) / 100,
          foreground: Math.round(colours.contrast(ink, fill) * 100) / 100,
        };
      });
  });
}

/** Every visible badge of the open page stands out, and so do `expected`, which are among them. */
async function expectReadableBadges(page: Page, expected: RegExp[]): Promise<void> {
  const badges = await badgesOf(page);
  for (const text of expected) {
    expect(badges.filter((badge) => text.test(badge.text)), `a badge reading ${text}`).not.toEqual([]);
  }
  const hidden = badges.filter((badge) => badge.background < MIN_BACKGROUND_CONTRAST || badge.foreground < MIN_TEXT_CONTRAST);
  expect(hidden, `badges the dark theme hides on ${page.url()}`).toEqual([]);
}

/**
 * The ID of the seeded job whose host was unreachable: among the failed jobs
 * of e2e-template, the one whose execution command marks it.
 */
async function unreachableJobId(page: Page): Promise<string> {
  const template = await templateId(page, 'e2e-template');
  for (let pageNo = 1; pageNo <= 10; pageNo++) {
    await page.goto(`/job/index?template_id=${template}&status=failed&page=${pageNo}`);
    const ids = await page.locator('#page-content table.table tbody tr td:first-child a').evaluateAll(
      (links) => links.map((link) => (link.textContent ?? '').trim().replace('#', '')),
    );
    for (const id of ids) {
      const poll = await page.request.get(`/job/log-poll?id=${id}&after=2147483647`);
      if (((await poll.json()) as { execution_command?: string }).execution_command === UNREACHABLE_JOB) {
        return id;
      }
    }
    if (ids.length === 0) {
      break;
    }
  }
  throw new Error(`No failed job of e2e-template is marked ${UNREACHABLE_JOB}: run php yii e2e/seed`);
}

test.describe('regression: badges the dark theme hid', () => {
  test('the job list and a job page with an unreachable host', async ({ page }) => {
    const jobId = await unreachableJobId(page);
    // The list page that shows the job, with its unreachable recap.
    const row = page.locator('#page-content table.table tbody tr').filter({ has: page.locator(`a[href$="/job/view?id=${jobId}"]`) });
    await expect(row.locator('.badge', { hasText: 'unreach' })).toHaveText('1 unreach');
    await expectReadableBadges(page, [/^Failed$/, /^1 unreach$/]);

    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#page-content table').filter({ hasText: UNREACHABLE_TASK }).locator('.badge')).toHaveText('unreachable');
    await expectReadableBadges(page, [/^Failed$/, /^unreachable$/, /^1 unreachable$/]);
  });

  test('the webhook list', async ({ page }) => {
    await page.goto('/webhook/index');
    await expectReadableBadges(page, [/^No$/]);
  });

  test('the approval list with a canceled request', async ({ page }) => {
    await page.goto('/approval/index?status=canceled');
    await expectReadableBadges(page, [/^Canceled$/]);
  });

  test('the dashboard and its charts', async ({ page }) => {
    await page.goto('/site/index');
    await expectReadableBadges(page, []);

    // The charts load their data after the page: wait until both are drawn.
    await expect.poll(() => page.evaluate(() => {
      const chart = (window as unknown as { Chart?: { getChart: (id: string) => unknown } }).Chart;
      return chart !== undefined && chart.getChart('chart-jobs') !== undefined && chart.getChart('chart-tasks') !== undefined;
    })).toBe(true);
    await installColourHelpers(page);
    const colours = await page.evaluate(() => {
      const c = (window as unknown as { e2eColours: Colours }).e2eColours;
      type Dataset = { label: string; backgroundColor: string };
      const chart = (window as unknown as { Chart: { getChart: (id: string) => { data: { datasets: Dataset[] } } } }).Chart;
      const probe = document.createElement('span');
      document.body.appendChild(probe);
      const result: { chart: string; label: string; contrast: number }[] = [];
      for (const id of ['chart-jobs', 'chart-tasks']) {
        const back = c.behind(document.getElementById(id) as Element);
        for (const dataset of chart.getChart(id).data.datasets) {
          // The browser writes any CSS colour as rgb().
          probe.style.color = dataset.backgroundColor;
          result.push({ chart: id, label: dataset.label, contrast: Math.round(c.contrast(c.parse(getComputedStyle(probe).color), back) * 100) / 100 });
        }
      }
      probe.remove();
      return result;
    });
    expect(colours.map((colour) => `${colour.chart} ${colour.label}`)).toContain('chart-tasks unreachable');
    expect(colours.filter((colour) => colour.contrast < MIN_BACKGROUND_CONTRAST), 'chart colours the dark theme hides').toEqual([]);
  });
});

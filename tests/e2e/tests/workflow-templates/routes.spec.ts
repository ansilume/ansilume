import { test, expect, Locator, Page } from '@playwright/test';
import { HOST_AFTER_STEP, HOST_RULE, HOST_STEP, HOST_TEMPLATE, HOST_WORKFLOW } from '../../lib/inventories';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  ALWAYS_WINS_TITLE,
  APPROVAL_STEP_SUFFIX,
  CANNOT_RUN_TITLE,
  GATED_BADGE,
  GATE_RULE,
  GATED_JOB_TEMPLATE,
  JOB_TEMPLATE,
  LEGACY_MISSING_STEP_ID,
  LEGACY_STEPS,
  LEGACY_WORKFLOW,
  LEGEND,
  LOOP_CHECK,
  LOOP_RETRY,
  LOOP_TITLE,
  LOOP_WORKFLOW,
  MISSING_TITLE,
  NEXT_TITLE,
  PAUSE_ALWAYS_NOTE,
  PART_SEPARATOR,
  PAUSE_FAILURE_TITLE,
  POSITION_TITLE,
  REFUSED_APPROVAL_TITLE,
  ROUTES_STEPS,
  ROUTES_WORKFLOW,
  ROUTE_ALSO_AFTER_FAILED_JOB,
  ROUTE_AFTER_REFUSED_APPROVAL,
  ROUTE_END_FAILS,
  ROUTE_END_SUCCEEDS,
  ROUTE_UNUSED,
  START_BADGE,
  START_TITLE,
  STEPS_COLUMNS,
  SUCCESS_PATH_LOOP_NOTE,
  UNREACHABLE_BADGE,
  UNREACHABLE_TITLE,
  gatedInventoryTitle,
  gatedTitle,
  routeCell,
  routeLoop,
  routeMissing,
  routeNext,
  routeTo,
  stepLabel,
  successPath,
  templateStepNames,
  templateStepPositions,
  templateStepRow,
  typeBadge,
} from '../../lib/workflows';

/**
 * The steps table names where every branch really leads, the way a run
 * takes it: an empty branch goes on with the next step in the list
 * ("(next)") or ends the run after the last step, a branch back to the same
 * or an earlier step is a loop (↺), a branch the step never takes reads "—"
 * with the reason as its title. The success path above the table and the
 * legend below it explain the order.
 *
 * Fixtures (commands/E2eWorkflowUxSeeder.php), which the specs only read:
 * e2e-routes-workflow, every kind of cell; e2e-routes-legacy-workflow, steps
 * older versions could save; e2e-routes-loop-workflow, a success path that
 * repeats. Read by e2e-admin; operators and viewers see the same table
 * without the actions (step-editing-rbac.spec.ts).
 */

/** The cells of a row: its position cell, type badge, target, and the three route cells' texts. */
async function expectRow(
  row: Locator,
  cells: { position: string; type: string; target: string; success: string; failure: string; always: string },
): Promise<void> {
  await expect(row.locator('[data-position]')).toHaveText(cells.position);
  await expect(typeBadge(row)).toHaveText(cells.type);
  await expect(row.locator('td[data-target-cell]')).toHaveText(cells.target);
  await expect(routeCell(row, 'success')).toHaveText(cells.success);
  await expect(routeCell(row, 'failure')).toHaveText(cells.failure);
  await expect(routeCell(row, 'always')).toHaveText(cells.always);
}

/** The hover texts of the parts of a route cell. */
async function partTitles(cell: Locator): Promise<(string | null)[]> {
  return cell.locator(':scope > span:not([data-route-note])').evaluateAll((spans) => spans.map((span) => span.getAttribute('title')));
}

/** Whether the open page scrolls sideways: wider than the viewport. */
async function pageOverflows(page: Page): Promise<boolean> {
  return page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
}

test.describe('Routes of workflow steps (admin)', () => {
  test('every route cell of e2e-routes-workflow names where the branch leads', async ({ page }) => {
    await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const table = page.locator('#workflow-steps-table');
    await expect(table.locator('thead th')).toHaveText([...STEPS_COLUMNS, 'Actions']);
    await expect(table.locator('thead th').first()).toHaveAttribute('title', POSITION_TITLE);
    const s = ROUTES_STEPS;
    await expect(templateStepNames(page)).toHaveText([s.build, s.approve, s.deploy, s.verify, s.wait, s.cleanup, s.spare]);
    await expect(templateStepPositions(page)).toHaveText(['1', '2', '3', '4', '5', '6', '7']);

    const build = templateStepRow(page, s.build);
    await expectRow(build, {
      position: '1',
      type: 'Job',
      target: JOB_TEMPLATE,
      success: routeNext(2, s.approve),
      failure: routeTo(6, s.cleanup),
      always: ROUTE_UNUSED,
    });
    await expect(build.locator('[data-start-badge]')).toHaveText(START_BADGE);
    await expect(build.locator('[data-start-badge]')).toHaveAttribute('title', START_TITLE);
    await expect(routeCell(build, 'success')).toHaveAttribute('data-route-kind', 'next');
    expect(await partTitles(routeCell(build, 'success'))).toEqual([NEXT_TITLE]);
    await expect(routeCell(build, 'failure')).toHaveAttribute('data-route-kind', 'step');

    // An approval step's On Failure is its refused approval: empty, the run
    // ends as failed.
    const approve = templateStepRow(page, s.approve);
    await expectRow(approve, {
      position: '2',
      type: 'Approval',
      target: GATE_RULE,
      success: routeNext(3, s.deploy),
      failure: ROUTE_END_FAILS,
      always: ROUTE_UNUSED,
    });
    await expect(approve.locator('[data-start-badge]')).toHaveCount(0);

    // A gated job step: a job that ran and failed goes on with the next
    // step; a refused approval, after which the job never ran, ends the run.
    const deploy = templateStepRow(page, s.deploy);
    await expectRow(deploy, {
      position: '3',
      type: 'Job',
      target: GATED_JOB_TEMPLATE,
      success: routeNext(4, s.verify),
      failure: `${routeNext(4, s.verify)}${ROUTE_ALSO_AFTER_FAILED_JOB}${PART_SEPARATOR}${ROUTE_END_FAILS}${ROUTE_AFTER_REFUSED_APPROVAL}`,
      always: ROUTE_UNUSED,
    });
    await expect(deploy.locator('[data-gated-badge]')).toHaveText(GATED_BADGE);
    await expect(deploy.locator('[data-gated-badge]')).toHaveAttribute('title', gatedTitle(GATE_RULE));
    await expect(routeCell(deploy, 'failure')).toHaveAttribute('data-route-kind', 'mixed');
    expect(await partTitles(routeCell(deploy, 'failure'))).toEqual([NEXT_TITLE, REFUSED_APPROVAL_TITLE]);

    // Back to an earlier step: a loop.
    const verify = templateStepRow(page, s.verify);
    await expectRow(verify, {
      position: '4',
      type: 'Job',
      target: JOB_TEMPLATE,
      success: routeNext(5, s.wait),
      failure: routeLoop(3, s.deploy),
      always: ROUTE_UNUSED,
    });
    await expect(routeCell(verify, 'failure')).toHaveAttribute('data-route-kind', 'loop');
    expect(await partTitles(routeCell(verify, 'failure'))).toEqual([LOOP_TITLE]);

    // A pause step never fails: Resume continues with On Success.
    const wait = templateStepRow(page, s.wait);
    await expectRow(wait, {
      position: '5',
      type: 'Pause',
      target: '—',
      success: routeNext(6, s.cleanup),
      failure: ROUTE_UNUSED,
      always: ROUTE_UNUSED,
    });
    expect(await partTitles(routeCell(wait, 'failure'))).toEqual([PAUSE_FAILURE_TITLE]);

    // Both branches end the workflow, explicitly.
    await expectRow(templateStepRow(page, s.cleanup), {
      position: '6',
      type: 'Job',
      target: JOB_TEMPLATE,
      success: ROUTE_END_SUCCEEDS,
      failure: ROUTE_END_FAILS,
      always: ROUTE_UNUSED,
    });

    // No branch leads to the spare step; as the last step its empty
    // branches end the run.
    const spare = templateStepRow(page, s.spare);
    await expectRow(spare, {
      position: '7',
      type: 'Job',
      target: JOB_TEMPLATE,
      success: ROUTE_END_SUCCEEDS,
      failure: ROUTE_END_FAILS,
      always: ROUTE_UNUSED,
    });
    await expect(spare.locator('[data-unreachable-badge]')).toHaveText(UNREACHABLE_BADGE);
    await expect(spare.locator('[data-unreachable-badge]')).toHaveAttribute('title', UNREACHABLE_TITLE);
    await expect(page.locator('[data-unreachable-badge]')).toHaveCount(1);
    await expect(routeCell(spare, 'success')).toHaveAttribute('data-route-kind', 'end');
  });

  // commands/E2eInventoryGateSeeder.php: e2e-host-gate-workflow's job step
  // runs e2e-host-gated-template, which has no rule of its own; its
  // inventory, e2e-gated-inventory, has e2e-host-gate-rule. The step waits
  // for that rule like any gated job step: a refused approval follows only
  // On Failure, which, left empty, ends the run as failed.
  test('a job step gated by its job template\'s inventory says so, and a refused approval ends the run', async ({ page }) => {
    await openWorkflowTemplate(page, HOST_WORKFLOW);
    await expect(templateStepNames(page)).toHaveText([HOST_STEP, HOST_AFTER_STEP]);

    const step = templateStepRow(page, HOST_STEP);
    await expectRow(step, {
      position: '1',
      type: 'Job',
      target: HOST_TEMPLATE,
      success: routeNext(2, HOST_AFTER_STEP),
      failure: `${routeNext(2, HOST_AFTER_STEP)}${ROUTE_ALSO_AFTER_FAILED_JOB}${PART_SEPARATOR}${ROUTE_END_FAILS}${ROUTE_AFTER_REFUSED_APPROVAL}`,
      always: ROUTE_UNUSED,
    });
    await expect(step.locator('[data-gated-badge]')).toHaveText(GATED_BADGE);
    await expect(step.locator('[data-gated-badge]')).toHaveAttribute('title', gatedInventoryTitle(HOST_RULE));
    await expect(step.locator('[data-target-problem]')).toHaveCount(0);
    await expect(routeCell(step, 'failure')).toHaveAttribute('data-route-kind', 'mixed');
    expect(await partTitles(routeCell(step, 'failure'))).toEqual([NEXT_TITLE, REFUSED_APPROVAL_TITLE]);

    // The pause step after it is no gated job step.
    const after = templateStepRow(page, HOST_AFTER_STEP);
    await expectRow(after, {
      position: '2',
      type: 'Pause',
      target: '—',
      success: ROUTE_END_SUCCEEDS,
      failure: ROUTE_UNUSED,
      always: ROUTE_UNUSED,
    });
    await expect(after.locator('[data-gated-badge]')).toHaveCount(0);
    await expect(page.locator('[data-gated-badge]')).toHaveCount(1);
    // Admins may change the steps: no lock.
    await expect(page.locator('#wf-approval-steps-locked-notice')).toHaveCount(0);
    await expect(step.locator('a[data-action="edit"]')).toHaveCount(1);
  });

  test('the success path above the table and the legend below it explain the order', async ({ page }) => {
    await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const s = ROUTES_STEPS;
    await expect(page.locator('#wf-success-path')).toContainText('Success path:');
    await expect(page.locator('[data-success-path]')).toHaveText(successPath(
      stepLabel(1, s.build),
      `${stepLabel(2, s.approve)}${APPROVAL_STEP_SUFFIX}`,
      stepLabel(3, s.deploy),
      stepLabel(4, s.verify),
      stepLabel(5, s.wait),
      stepLabel(6, s.cleanup),
      ROUTE_END_SUCCEEDS,
    ));
    await expect(page.locator('#wf-success-path-loop')).toHaveCount(0);
    await expect(page.locator('#wf-steps-legend')).toHaveText(LEGEND);
  });

  test('steps older versions saved: a missing step, a deleted job template, an Always branch of a pause step', async ({ page }) => {
    await openWorkflowTemplate(page, LEGACY_WORKFLOW);
    const l = LEGACY_STEPS;
    await expect(templateStepNames(page)).toHaveText([l.start, l.gone, l.pause, l.end]);

    // A branch to a step that no longer exists fails the run there.
    const start = templateStepRow(page, l.start);
    await expect(routeCell(start, 'success')).toHaveText(routeMissing(LEGACY_MISSING_STEP_ID));
    await expect(routeCell(start, 'success')).toHaveAttribute('data-route-kind', 'missing');
    await expect(routeCell(start, 'success')).toHaveClass(/text-danger/);
    expect(await partTitles(routeCell(start, 'success'))).toEqual([MISSING_TITLE]);
    await expect(routeCell(start, 'failure')).toHaveText(routeNext(2, l.gone));
    await expect(page.locator('[data-success-path]')).toHaveText(successPath(stepLabel(1, l.start), routeMissing(LEGACY_MISSING_STEP_ID)));

    // A job step whose job template was deleted cannot run: no branch is followed.
    const gone = templateStepRow(page, l.gone);
    await expect(gone.locator('[data-target-problem]')).toHaveText(/^job template ID \d+ no longer exists · runs fail here$/);
    for (const route of ['success', 'failure', 'always'] as const) {
      await expect(routeCell(gone, route)).toHaveText(ROUTE_UNUSED);
      expect(await partTitles(routeCell(gone, route))).toEqual([CANNOT_RUN_TITLE]);
    }
    // Regression (wave 5, R5-ROUTE-3): runs fail there, so the steps only it
    // leads to are unreachable; they had no badge.
    await expect(gone.locator('[data-unreachable-badge]')).toHaveCount(0);
    for (const name of [l.pause, l.end]) {
      await expect(templateStepRow(page, name).locator('[data-unreachable-badge]')).toHaveText(UNREACHABLE_BADGE);
      await expect(templateStepRow(page, name).locator('[data-unreachable-badge]')).toHaveAttribute('title', UNREACHABLE_TITLE);
    }
    await expect(page.locator('[data-unreachable-badge]')).toHaveCount(2);

    // An Always branch of a pause step is taken on Resume, instead of On Success.
    const pause = templateStepRow(page, l.pause);
    await expect(routeCell(pause, 'success')).toHaveText(ROUTE_UNUSED);
    expect(await partTitles(routeCell(pause, 'success'))).toEqual([ALWAYS_WINS_TITLE]);
    await expect(routeCell(pause, 'failure')).toHaveText(ROUTE_UNUSED);
    await expect(routeCell(pause, 'always')).toHaveText(`${routeTo(4, l.end)} ${PAUSE_ALWAYS_NOTE}`);
    await expect(routeCell(pause, 'always').locator('[data-route-note]')).toHaveText(PAUSE_ALWAYS_NOTE);
  });

  test('a success path that leads back repeats, and the page says so', async ({ page }) => {
    await openWorkflowTemplate(page, LOOP_WORKFLOW);
    const check = templateStepRow(page, LOOP_CHECK);
    // A job step that fails and leads to itself retries.
    await expect(routeCell(check, 'success')).toHaveText(routeNext(2, LOOP_RETRY));
    await expect(routeCell(check, 'failure')).toHaveText(routeLoop(1, LOOP_CHECK));
    const retry = templateStepRow(page, LOOP_RETRY);
    await expect(routeCell(retry, 'success')).toHaveText(routeLoop(1, LOOP_CHECK));
    await expect(routeCell(retry, 'success')).toHaveAttribute('data-route-kind', 'loop');
    await expect(routeCell(retry, 'failure')).toHaveText(ROUTE_END_FAILS);
    await expect(page.locator('[data-success-path]')).toHaveText(
      successPath(stepLabel(1, LOOP_CHECK), stepLabel(2, LOOP_RETRY), routeLoop(1, LOOP_CHECK)),
    );
    await expect(page.locator('#wf-success-path-loop')).toHaveText(SUCCESS_PATH_LOOP_NOTE);
  });

  // Regression: the steps table had no scroll container, so at phone width
  // it pushed the page wider than the screen. It scrolls inside its own box
  // now (.table-responsive).
  test('regression: the steps table overflowed the page at phone width', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openWorkflowTemplate(page, ROUTES_WORKFLOW);
    const table = page.locator('#workflow-steps-table');
    const box = page.locator('.table-responsive').filter({ has: table });
    await expect(box).toHaveCount(1);
    const sizes = await box.evaluate((element) => ({
      scrollWidth: element.scrollWidth,
      clientWidth: element.clientWidth,
      right: element.getBoundingClientRect().right,
      viewport: window.innerWidth,
    }));
    // The table is wider than the screen, and scrolls inside its box ...
    expect(sizes.scrollWidth).toBeGreaterThan(sizes.clientWidth);
    // ... which ends within the screen ...
    expect(sizes.right).toBeLessThanOrEqual(sizes.viewport);
    // ... so the page itself does not scroll sideways.
    expect(await pageOverflows(page)).toBe(false);
  });
});

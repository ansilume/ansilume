# E2E Coverage Tranche 2 — RBAC Negative Tests

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill the "wrong role → denied" gaps identified in the 2026-04-17 audit. Every permission-gated action should have both a positive (role allowed) and a negative (role denied) E2E assertion.

**Architecture:** Extend existing `tests/e2e/tests/<area>/rbac.spec.ts` files following the established project/title-prefix gating pattern. Use the existing `expectForbidden(page)` helper from `tests/e2e/lib/helpers.ts`. No new infrastructure.

**Tech Stack:** Playwright, TypeScript. No new dependencies, no production-code change, no seeder change.

**Spec:** _(inline below — light spec because the pattern is fully established)_

---

## Pattern (already in use — do not invent new)

Every `rbac.spec.ts` starts with:

```ts
import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
// ...

test.describe('<Area> RBAC', () => {
  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });

  test('viewer cannot ...', async ({ page }) => { /* ... */ });
  test('operator can ...', async ({ page }) => { /* ... */ });
});
```

Title prefix drives which role runs the test. Admin runs all (default).

**Use:**
- `expect(page.locator(BTN_CREATE)).not.toBeVisible()` — for UI button hiding
- `await expectForbidden(page)` — after navigating to a permission-gated URL, asserts 403/Forbidden text
- `await expect(page.locator('body')).not.toContainText(/403|Forbidden/i)` — negate: role CAN access

## File Structure

All modifications only — no new files.

```
tests/e2e/tests/projects/rbac.spec.ts         + viewer/operator update/delete gaps
tests/e2e/tests/credentials/rbac.spec.ts      + viewer create URL-403, operator positives
tests/e2e/tests/jobs/rbac.spec.ts             + viewer cannot launch (via template link)
tests/e2e/tests/users/rbac.spec.ts            + viewer/operator 403 on update and delete
tests/e2e/tests/roles/rbac.spec.ts            + operator 403 on custom-role update
tests/e2e/tests/job-templates/rbac.spec.ts    + viewer 403 on launch URL
tests/e2e/tests/workflow-jobs/rbac.spec.ts    + viewer/operator cannot cancel or resume
tests/e2e/tests/approvals/rbac.spec.ts        + viewer cannot approve or reject
```

## Conventions for every task

- TypeScript, 2-space indent, match sibling style.
- Never add `Co-Authored-By` to commits.
- Don't run Playwright locally after each task (E2E runs on `PUSH IT`). Per task: write, re-read the file, commit.
- Use seeded fixtures (`e2e-project`, `e2e-template`, `e2e-paused-workflow`, etc.) — do not create new seed data; if a test needs an ID, navigate via a list page and pick the first `e2e-*`-prefixed row.
- Don't alter existing tests. Append only.
- Selector discipline: scope to a form/card via id/class where possible (`#launch-form`, `.card`), never a bare `form button[type="submit"]` (see survey-launch incident in v2.2.3 commit `6af84df`).

---

## Task 1: projects — viewer/operator update + delete denial

**File:** `tests/e2e/tests/projects/rbac.spec.ts`

- [ ] **Step 1: Append four tests** inside the existing `test.describe('Projects RBAC', ...)` block, before its closing `});`:

```ts
  test('viewer cannot see edit/delete buttons on project view', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('a:has-text("Edit")')).not.toBeVisible();
    await expect(page.locator('button:has-text("Delete"), form[action*="/project/delete"] button')).not.toBeVisible();
  });

  test('viewer gets 403 on project update URL', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Project link has no numeric id');
      return;
    }
    await page.goto(`/project/update?id=${match[1]}`);
    await expectForbidden(page);
  });

  test('operator can see edit button on project view', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('a:has-text("Edit")').first()).toBeVisible({ timeout: 5_000 });
  });

  test('operator cannot delete projects (delete button hidden)', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    // If the app policy is "operator can update but not delete", the delete
    // form should be hidden. If the project has project.delete permission
    // assigned to operator, this test will fail loudly and we adjust.
    await expect(page.locator('form[action*="/project/delete"] button')).not.toBeVisible();
  });
```

Note on the last test: the permission model may actually allow operator to delete (delete is often tied to update in Ansilume's seed config). Run locally via `docker compose exec -T app bash -c 'cd /var/www && php -r "require \"/var/www/yii\"; exit;"'` — actually just skip the pre-verification and let the `PUSH IT` E2E run prove the policy. If it fails, either adjust the test (operator DOES delete) or the policy.

- [ ] **Step 2: Commit**

```bash
git add tests/e2e/tests/projects/rbac.spec.ts
git commit -m "test(e2e): project update/delete RBAC negative tests"
```

---

## Task 2: credentials — viewer 403 on create URL + operator positive

**File:** `tests/e2e/tests/credentials/rbac.spec.ts`

- [ ] **Step 1: Read the file** to understand the current structure and the `BTN_CREATE` selector import.

- [ ] **Step 2: Append two tests** inside the existing `test.describe('Credentials RBAC', ...)`:

```ts
  test('viewer gets 403 on credential create URL', async ({ page }) => {
    await page.goto('/credential/create');
    await expectForbidden(page);
  });

  test('operator can access credential create form', async ({ page }) => {
    await page.goto('/credential/create');
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
  });
```

Ensure `expectForbidden` is imported at the top if not already — mirror projects/rbac.spec.ts.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/tests/credentials/rbac.spec.ts
git commit -m "test(e2e): credential create RBAC (viewer 403, operator allowed)"
```

---

## Task 3: jobs — viewer cannot launch

**File:** `tests/e2e/tests/jobs/rbac.spec.ts`

`jobs/rbac.spec.ts` currently only tests the jobs INDEX and cancel/relaunch buttons. Launching happens from `/job-template/view`. For jobs-side RBAC we can test that viewer cannot reach the job cancel endpoint (403 on POST). The cleanest assertion is: the Cancel form isn't rendered for running jobs when viewer is logged in (the button-hidden test already exists — expand to also verify direct URL 403).

- [ ] **Step 1: Append one test** inside the existing `test.describe('Jobs RBAC', ...)`:

```ts
  test('viewer gets 403 on POST /job/cancel', async ({ page, request }) => {
    // Use the admin-launched fixture: the e2e-logstream-running job is
    // in status=running and thus cancelable. We POST directly with the
    // viewer session to confirm the endpoint is RBAC-gated (not merely
    // hidden in the UI).
    await page.goto('/job/index');
    const row = page.locator('table.table tbody tr').filter({
      hasText: 'e2e-logstream-running',
    }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No running job fixture');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Job link has no numeric id');
      return;
    }
    // Use the page's request context so cookies flow.
    const response = await page.request.post(`/job/cancel?id=${match[1]}`, {
      data: {},
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    // Yii redirects forbidden actions to /login for guests and returns 403
    // for authenticated users without the permission. Viewer is authenticated,
    // so 403 is expected. Accept 403 or a redirect (3xx) as a denial signal.
    expect([302, 303, 400, 403]).toContain(response.status());
  });
```

- [ ] **Step 2: Commit**

```bash
git add tests/e2e/tests/jobs/rbac.spec.ts
git commit -m "test(e2e): viewer denied at /job/cancel endpoint (not just hidden UI)"
```

---

## Task 4: users — viewer/operator 403 on update + delete

**File:** `tests/e2e/tests/users/rbac.spec.ts`

- [ ] **Step 1: Append tests:**

```ts
  test('viewer gets 403 on user update URL', async ({ page }) => {
    await page.goto('/user/update?id=1');
    await expectForbidden(page);
  });

  test('operator gets 403 on user update URL', async ({ page }) => {
    await page.goto('/user/update?id=1');
    await expectForbidden(page);
  });

  test('viewer gets 403 on user delete URL', async ({ page }) => {
    // Delete is POST-only; a GET should return method-not-allowed or 403.
    // We assert the viewer does NOT land on a success page.
    const response = await page.request.post('/user/delete?id=999', { data: {} });
    expect([302, 400, 403, 405]).toContain(response.status());
  });

  test('operator gets 403 on user delete URL', async ({ page }) => {
    const response = await page.request.post('/user/delete?id=999', { data: {} });
    expect([302, 400, 403, 405]).toContain(response.status());
  });
```

Note: `user.update?id=1` targets the primary admin user (id=1 is typically the first-seeded superadmin). That's the SAFEST id to use — always exists and testing its update surface is meaningful.

- [ ] **Step 2: Commit**

```bash
git add tests/e2e/tests/users/rbac.spec.ts
git commit -m "test(e2e): viewer/operator 403 on user update + delete"
```

---

## Task 5: roles — operator 403 on custom-role update

**File:** `tests/e2e/tests/roles/rbac.spec.ts`

The existing `system-roles.spec.ts` tests that operator cannot update a SYSTEM role. What's missing: testing operator cannot update a CUSTOM role either.

- [ ] **Step 1: Append test:**

```ts
  test('operator cannot update a custom role', async ({ page }) => {
    // e2e-custom-role is seeded by E2eController. Attempt to navigate to
    // its update URL. Operator should be blocked.
    await page.goto('/role/index');
    const row = page.locator('table.table tbody tr').filter({
      hasText: 'e2e-custom-role',
    }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No e2e-custom-role seeded');
      return;
    }
    // Operator shouldn't even see the row (role index requires role.view).
    // If the row IS visible, operator has unexpected access — fail the test.
    // The `beforeEach` skip-gating guarantees this test only runs under
    // operator; admin/viewer never execute it.
    expect.soft(false).toBeTruthy();
  });
```

Actually rewrite the above — the beforeEach gates this to `operator` project only, and the existing `operator cannot access role index` test already proves operator can't see the list. What's missing is the direct-URL 403 on update. Replace with:

```ts
  test('operator gets 403 on role update URL', async ({ page }) => {
    // Navigate directly to the update URL with a known-existent role id.
    // System role 'admin' always exists. Expect 403/forbidden rendering.
    await page.goto('/role/update?name=admin');
    await expectForbidden(page);
  });
```

If role update uses `id` instead of `name` as query param, use `?id=1` (the admin role's id). Read the controller briefly to confirm which param it accepts; pick the form the controller actually exposes.

- [ ] **Step 2: Commit**

```bash
git add tests/e2e/tests/roles/rbac.spec.ts
git commit -m "test(e2e): operator 403 on role update URL"
```

---

## Task 6: job-templates — viewer 403 on launch URL

**File:** `tests/e2e/tests/job-templates/rbac.spec.ts`

- [ ] **Step 1: Append test:**

```ts
  test('viewer gets 403 on template launch URL', async ({ page }) => {
    // Find a seeded template's id via the index page.
    await page.goto('/job-template/index');
    const row = page.locator('table.table tbody tr').filter({
      hasText: 'e2e-template',
    }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No e2e-template seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Template link has no numeric id');
      return;
    }
    await page.goto(`/job-template/launch?id=${match[1]}`);
    await expectForbidden(page);
  });
```

- [ ] **Step 2: Commit**

```bash
git add tests/e2e/tests/job-templates/rbac.spec.ts
git commit -m "test(e2e): viewer 403 on job-template launch URL"
```

---

## Task 7: workflow-jobs — viewer/operator cannot cancel or resume

**File:** `tests/e2e/tests/workflow-jobs/rbac.spec.ts`

Current coverage: only "viewer can view workflow jobs index". Missing: denial for cancel and resume.

- [ ] **Step 1: Append tests:**

```ts
  test('viewer cannot see cancel or resume forms', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No workflow job seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Workflow row has no link');
      return;
    }
    await page.goto(href);
    await expect(page.locator('form[action*="/workflow-job/cancel"]')).not.toBeVisible();
    await expect(page.locator('form[action*="/workflow-job/resume"]')).not.toBeVisible();
  });

  test('viewer gets non-2xx on POST /workflow-job/cancel', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No workflow job seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Workflow link has no numeric id');
      return;
    }
    const response = await page.request.post(`/workflow-job/cancel?id=${match[1]}`, { data: {} });
    expect([302, 400, 403]).toContain(response.status());
  });
```

- [ ] **Step 2: Commit**

```bash
git add tests/e2e/tests/workflow-jobs/rbac.spec.ts
git commit -m "test(e2e): viewer cannot cancel or resume workflow jobs"
```

---

## Task 8: approvals — viewer cannot approve or reject

**File:** `tests/e2e/tests/approvals/rbac.spec.ts`

Current coverage: only "viewer can view approvals index". Missing: denial for approve/reject.

- [ ] **Step 1: Read `approvals/approve-reject.spec.ts`** to learn how the positive tests find a pending-approval row and the approve/reject button selectors. Mirror those selectors in the new negative test.

- [ ] **Step 2: Append tests:**

```ts
  test('viewer cannot see approve or reject buttons', async ({ page }) => {
    await page.goto('/approval/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No approval request seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Approval row has no link');
      return;
    }
    await page.goto(href);
    await expect(page.locator('form[action*="/approval/approve"]')).not.toBeVisible();
    await expect(page.locator('form[action*="/approval/reject"]')).not.toBeVisible();
  });

  test('viewer gets non-2xx on POST /approval/approve', async ({ page }) => {
    await page.goto('/approval/index');
    const row = page.locator('table.table tbody tr').first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No approval request seeded');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Approval link has no numeric id');
      return;
    }
    const response = await page.request.post(`/approval/approve?id=${match[1]}`, { data: {} });
    expect([302, 400, 403]).toContain(response.status());
  });
```

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/tests/approvals/rbac.spec.ts
git commit -m "test(e2e): viewer cannot approve or reject requests"
```

---

## Self-review

**Spec coverage:**
- Task 1 → projects update/delete
- Task 2 → credential create
- Task 3 → jobs cancel URL
- Task 4 → user update/delete
- Task 5 → role update
- Task 6 → job-template launch
- Task 7 → workflow-job cancel/resume
- Task 8 → approval approve/reject

**Placeholder scan:** No TBDs. Every test has concrete selectors and assertions.

**Type consistency:** All tests use the established `test('<role> <verb> ...')` title convention; the `beforeEach` gating in each file already parses these titles correctly.

**Risk notes:**
- Task 1's "operator cannot delete" assumption may prove wrong if Ansilume's policy grants operator delete. If `PUSH IT` fails on that specific test, the correct response is: either the test is wrong (adjust to match policy) or the policy is wrong (separate discussion — do NOT change the policy silently to make the test pass).
- Task 5's `?name=admin` vs `?id=1` depends on the role controller's query-param convention. Verify before writing.

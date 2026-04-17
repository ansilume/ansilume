# E2E Coverage Tranche 1 — Design

**Date:** 2026-04-17
**Status:** Draft → awaiting user review
**Target release:** next patch (v2.2.2 candidate)

## 1. Goals

Close the four highest-risk Playwright E2E coverage gaps identified by the
2026-04-17 audit of v2.2.1:

1. **Live job-log streaming** — `JobController::actionLogPoll` has no E2E test.
2. **Runner UI create + token regenerate** — `RunnerController::actionCreate`
   and `actionRegenerateToken` UI flows are untested.
3. **Workflow-job resume** — `WorkflowJobController::actionResume` UI button is
   untested (API-covered only).
4. **Job-template launch with survey fields** — survey-form launch flow is
   untested; the seeded `e2e-template` has no survey so existing launch tests
   never render survey inputs.

The rest of the audit ("Tranche 2 / 3") is explicitly deferred.

## 2. Non-goals

- RBAC negative (denied-role) tests for the new flows — Tranche 2.
- Form-validation edge cases (special characters, long strings, SQL-injection
  patterns) — Tranche 2.
- Multi-user concurrent-access scenarios.
- Session-timeout / auto-logout tests.
- A dedicated admin-only E2E backend endpoint that appends log chunks during a
  running test ("Variante C" from brainstorming — deliberately rejected:
  high engineering cost, low marginal coverage).
- Any API, schema, or OpenAPI change.
- Any new PHPUnit tests. The one backend surface these tests touch
  (survey-merge) is already covered by `JobLaunchServiceMergeTest`.

## 3. User-facing behavior verified

### 3.1 Log streaming

- When a user opens the view page of a **finished** job, all persisted
  `job_log` chunks render and the status badge reflects the final state.
- When a user opens the view page of a **running** job, the polling JS is
  active: a `/job/<id>/log-poll` request goes out within a few seconds.
  Initial chunks render; the page does not need to show new chunks arriving
  (that would require variant C).

### 3.2 Runner create + token regenerate

- An admin can fill the runner-create form (`name`, `runner_group`), submit,
  and see the newly created runner's token displayed on the redirected view.
- On an existing runner's view, an admin can click "Regenerate token",
  confirm the action, and see a token that differs from the previously
  displayed one.

### 3.3 Workflow-job resume

- On the view page of a workflow job in `waiting` state (paused at a step
  awaiting approval / manual resume), the admin sees an enabled "Resume"
  button. Clicking it produces a success flash and transitions the job out
  of `waiting`.

### 3.4 Survey launch

- When an admin opens the launch form for a template with `survey_fields`,
  the three field types render (text, boolean checkbox, select).
- Submitting with all fields filled creates a new job whose `extra_vars`
  contain the chosen values (merged by `JobLaunchService`).
- Submitting with a required survey field blank produces a validation error
  and does not create a job.

## 4. Architecture

### 4.1 Affected files

```
commands/E2eController.php                 + private invocations for new seeders
commands/E2eLogStreamSeeder.php            NEW — seeds 2 jobs + 5 log chunks
commands/E2eWorkflowPausedSeeder.php       NEW — seeds paused workflow + job
commands/E2eSurveyTemplateSeeder.php       NEW — seeds a survey-equipped template
tests/e2e/tests/jobs/log-streaming.spec.ts NEW — 2 tests
tests/e2e/tests/runners/create-token.spec.ts NEW — 2 tests
tests/e2e/tests/workflow-jobs/resume.spec.ts NEW — 1 test
tests/e2e/tests/job-templates/survey-launch.spec.ts NEW — 2 tests
```

No production-code change. No migration. No OpenAPI change.

### 4.2 Seeder pattern

All three new seeders follow the existing `commands/E2eArtifactSeeder.php`
convention:

- Constructor takes `callable(string): void $logger`.
- Public `seed(...)` method is idempotent: starts with a targeted
  `ModelClass::deleteAll([<condition on e2e-prefix>])` sweep, then re-creates
  the fixture set. Delete-and-recreate (not early-return-if-exists) — this
  matches the existing style and survives partial failures without manual
  cleanup. Exception: `E2eSurveyTemplateSeeder` may re-use the already-seeded
  `e2e-project` / `e2e-inventory` / `e2e-credential` / `e2e-runner-group`
  when present; only the template itself and its jobs are delete-and-recreate.
- Identifiers prefixed `e2e-logstream-`, `e2e-paused-workflow-`,
  `e2e-survey-` so they do not collide with existing fixtures.

### 4.3 Seeder signatures

**`E2eLogStreamSeeder::seed(int $userId, int $templateId): void`**

Creates:
- Job 1: `execution_command = 'e2e-logstream-finished'`,
  `status = successful`, `exit_code = 0`, `finished_at = time() - 60`,
  plus 3 `job_log` rows with sequences 1–3 and realistic ANSI-free content.
- Job 2: `execution_command = 'e2e-logstream-running'`,
  `status = running`, `started_at = time() - 10`, `finished_at = null`,
  plus 2 `job_log` rows with sequences 1–2.

**`E2eWorkflowPausedSeeder::seed(int $userId, int $jobTemplateId): void`**

Creates:
- 1 `WorkflowTemplate` named `e2e-paused-workflow` with 2 steps, both
  referencing `$jobTemplateId` (re-uses the existing e2e job template).
- 1 `WorkflowJob` in status `waiting` (step 1 completed, step 2 paused for
  manual resume). Stepwise state modelled with whatever fields the existing
  `workflow_job` / `workflow_step_run` tables expose — the implementer reads
  those models first and mirrors the patterns already used by
  `workflow-jobs/approval-flow.spec.ts`'s seeding paths.

**`E2eSurveyTemplateSeeder::seed(int $userId, int $projectId, int $inventoryId, int $credentialId, int $runnerGroupId): int`**

Returns the new template ID so the test can link to it.

Survey-fields JSON (stored on `job_template.survey_fields`):

```json
[
  {
    "name": "target_env",
    "label": "Target environment",
    "type": "text",
    "required": true,
    "default": "staging"
  },
  {
    "name": "dry_run",
    "label": "Dry run",
    "type": "boolean",
    "required": false,
    "default": false
  },
  {
    "name": "log_level",
    "label": "Log level",
    "type": "select",
    "required": true,
    "options": ["debug", "info", "warn"],
    "default": "info"
  }
]
```

The exact schema of `survey_fields` must match whatever
`JobLaunchService::mergeSurveyIntoExtraVars` (or the equivalent merging
method) expects — the implementer reads the existing handling code before
writing the JSON, to stay aligned with the production format.

### 4.4 Wiring in `E2eController.php`

The controller's action that seeds test fixtures (currently calls
`seedJobWithArtifacts(...)`, `seedTeam(...)`, etc.) gains three sibling
invocations in the same style:

```php
$this->seedLogStreamFixtures($userId, $templateId);
$this->seedPausedWorkflow($userId, $templateId);
$surveyTemplateId = $this->seedSurveyTemplate($userId, $projectId, $inventoryId, $credentialId, $runnerGroupId);
```

Each private wrapper instantiates its seeder with `function (string $msg): void { $this->stdout($msg); }` and calls `seed(...)`.

## 5. Test specs

Every spec file follows the existing pattern in `tests/e2e/tests/`: relies on
Playwright's pre-configured admin `storageState` unless otherwise noted,
uses `test.skip(...)` to degrade gracefully when a fixture is missing, and
imports `{ test, expect }` from `@playwright/test`.

### 5.1 `jobs/log-streaming.spec.ts`

**Test A — `finished job renders all log chunks`:**
- Navigate to the finished log-stream job's view page (locate by
  `execution_command = 'e2e-logstream-finished'` — the test reads the job ID
  from the jobs list page, filtering by the command string).
- Assert the three seeded chunks are visible in the log container.
- Assert status badge shows "successful".
- Assert no active polling: the last poll request on this page (if any)
  should end — observed by an absence of additional `/log-poll` requests
  over a 2s window.

**Test B — `running job polls and shows initial chunks`:**
- Locate and navigate to the running log-stream job.
- Install a request listener: `page.on('request', ...)` and record any URL
  containing `/log-poll`.
- Wait 3 seconds.
- Assert at least one `/log-poll` request was observed.
- Assert the two initial chunks rendered.

### 5.2 `runners/create-token.spec.ts`

**Test A — `admin creates a runner via UI and sees its token`:**
- Navigate to `/runner/create`.
- Fill `name = "e2e-ui-created-runner"`, select an existing `e2e-` runner
  group from the dropdown.
- Submit. Assert redirect to a runner view URL (`/runner/view?id=N`).
- Assert a token value is displayed (either in a code block or
  data-attribute) and is non-empty.

**Test B — `admin regenerates runner token`:**
- Navigate to a seeded runner's view page.
- Read the currently displayed token text.
- Click the "Regenerate token" button. Handle whatever confirmation dialog
  the UI presents (follow the pattern already in
  `tests/e2e/tests/runners/crud.spec.ts`).
- Assert a success flash appears.
- Assert the visible token text has changed.

### 5.3 `workflow-jobs/resume.spec.ts`

**Test A — `admin resumes a paused workflow job`:**
- Navigate to the paused workflow-job's view.
- Assert the status badge shows "waiting".
- Assert the Resume button is visible and enabled.
- Click Resume.
- Assert a success flash.
- Assert the status is no longer "waiting" (could be "running" or may have
  advanced to "successful" if the next step completes synchronously — the
  assertion checks "not waiting" rather than a specific next state,
  matching the service's actual contract).

### 5.4 `job-templates/survey-launch.spec.ts`

**Test A — `admin launches a survey template`:**
- Navigate to `/job-template/view?id=<surveyTemplateId>` (test reads the ID
  by filtering the template list by `name = 'e2e-survey-template'`).
- Click Launch.
- Assert the three survey fields render.
- Fill: `target_env = production`, check `dry_run`, set
  `log_level = debug`.
- Submit. Assert redirect to a new job view.
- Assert the job-view's extra-vars / survey-values panel reflects the
  submitted values. If the view does not render extra-vars in a
  greppable form, fall back to issuing a `GET /api/v1/jobs/<id>` inside
  the test (Playwright's `request` context with an admin API token
  seeded by `E2eController`) and asserting on the `extra_vars` JSON.

**Test B — `survey launch rejects missing required field`:**
- Open launch form.
- Clear `target_env` (or leave empty if no default prefilled).
- Submit.
- Assert a validation error message appears for `target_env`.
- Assert the browser is still on the launch form (no job created).

## 6. Risks and mitigations

1. **Polling-test flake** — the "running job polls" assertion may miss the
   first poll interval on slow CI. Mitigation: request listener installed
   before navigation; 10s timeout; `waitForRequest` with a URL predicate
   rather than a brittle count assertion.

2. **Runner-create pollution** — UI-created runners accumulate across runs.
   Mitigation: `E2eController` gains a
   `Runner::deleteAll(['like', 'name', 'e2e-ui-%'])` sweep at the top of its
   seed action, matching the delete-and-recreate pattern used elsewhere.

3. **Workflow resume state assumption** — the exact post-resume status
   depends on service internals; the assertion uses "not waiting" to stay
   robust against synchronous vs. asynchronous step progression.

4. **Survey-field markup uncertainty** — the implementer reads
   `views/job-template/launch.php` (or wherever the survey fields render)
   BEFORE writing the test, and uses the actual `name="..."` /
   `data-survey-field="..."` selectors the form emits. No guessing.

5. **Seeder idempotency on partial failure** — each seeder starts with a
   delete-all of its own prefix, so a failed mid-seed leaves no stale rows
   for the next run.

## 7. Acceptance

- All four new `*.spec.ts` files are present and run green against the e2e
  docker stack.
- `E2eController` exposes the three new fixtures. Running
  `php yii e2e/seed` twice in a row succeeds (idempotent).
- `bin/tests-e2e.sh` exits 0 with the new specs counted in the summary.
- No regressions in existing specs: the pre-existing `jobs/`, `runners/`,
  `workflow-jobs/`, and `job-templates/` specs still pass unchanged.

## 8. Out-of-scope / future

- **Tranche 2** (RBAC negative tests for operator/viewer on
  `project.create/update/delete`, `credential.create`, `job.launch`,
  `user.update`, `role.create/update`, `job-template.launch`,
  `workflow-job.cancel`).
- **Tranche 3** (form-validation edge cases, error pages, session timeout,
  multi-user concurrency, audit-log filter UX, TOTP recovery codes,
  notification delivery proof).
- Live log-append endpoint (brainstorming variant C).

## 9. Release

Normal `./bin/release patch` once merged (tests-only change, no user-visible
feature). Both seeders + specs land in the same release cut.

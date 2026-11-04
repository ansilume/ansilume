# Recovered failures on the dashboard: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task by task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A failed or timed-out job that a later, non-dry-run success of
the same job template recovered no longer shows as an open failure on the
dashboard. The job page, the job list and the API say which job recovered
it.

**Architecture:** One shared rule, used by the dashboard, the job page,
the job list, the jobs API and the analytics' MTTR: a succeeded,
non-dry-run job of the same template that started after the failure
finished.

**Tech stack:** PHP 8.5, Yii2 ActiveRecord, MariaDB, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-dashboard-recovered-failures-design.md`

## Global constraints

- CLAUDE.md applies:
  - strict types; `Html::encode()`;
  - the API and OpenAPI for the feature;
  - E2E for UI changes per role;
  - 100 % coverage;
  - no N+1 on list pages.
- No commits per task: commits come only with "PUSH IT".
- The terminal failures are `Job::terminalFailureStatuses()`, which are
  failed and timed out. Canceled and rejected jobs are never "recovered".
- The filter `recovered` takes `0`/`1`. Any other value is ignored, as
  every filter of `GET /api/v1/jobs` and `JobSearchForm` is.

## Review focus

1. Overlapping runs: a success that started before the failure finished
   does not recover it. Task 1: `testAnOverlappingSuccessDoesNotRecover`.
2. A dry run never recovers. Task 1: `testADryRunDoesNotRecover`.
3. Twenty failures on the dashboard and on a list page take one recovery
   query, not twenty. Task 1: `testRecoveredByTakesOneQuery`.
4. A recovered failure older than 24 hours never shows in the muted
   dashboard line. Task 2: `testTheDashboardLineCountsOnlyTheLast24Hours`.
5. Jobs without a job template, if any exist, are never recovered and
   never error. Task 1: `testAJobWithoutTemplateIsNeverRecovered`.

---

### Task 1: The recovery rule

**Files:**
- Create:
  - `services/RecoveredFailures.php`
  - `migrations/m000092_000000_add_job_recovery_index.php`, which adds
    index `idx_job_template_status_started` (`job_template_id`, `status`,
    `started_at`) when the query plan needs it; check with `EXPLAIN` on
    the test database
- Modify: `services/AnalyticsService.php` (`computeMttr()` uses the shared
  rule and ignores dry runs)
- Test:
  - `tests/integration/services/RecoveredFailuresTest.php`
  - `tests/integration/services/AnalyticsServiceTest.php` (extend)

**Interfaces:**
- **Produces:**
  - `RecoveredFailures::recoveredBy(array<int> $jobIds): array<int, int>`:
    each recovered failure's ID mapped to its recovering job's ID, the
    first one by `started_at`. One query.
  - `RecoveredFailures::filterRecovered(ActiveQuery $jobs, bool $recovered,
    string $alias = 'job'): ActiveQuery`: adds an `EXISTS` or `NOT EXISTS`
    condition, for terminal failures only.
  - `RecoveredFailures::recoveryCondition(string $failedAlias, string
    $successAlias): string`: the shared SQL condition, which MTTR reuses.

- [ ] **Step 1: Write the failing tests:**
  - `testALaterSuccessRecovers`;
  - `testAnOverlappingSuccessDoesNotRecover`;
  - `testADryRunDoesNotRecover`;
  - `testAnotherTemplateDoesNotRecover`;
  - `testTimedOutCountsCanceledDoesNot`;
  - `testTheFirstRecoveringJobIsNamed`;
  - `testRecoveredByTakesOneQuery` (count queries);
  - `testAJobWithoutTemplateIsNeverRecovered`;
  - `AnalyticsServiceTest::testMttrIgnoresDryRuns`.
- [ ] **Step 2: Run them and verify they fail.**
  Run: `docker compose exec -T app php -d memory_limit=1G
  vendor/bin/phpunit --no-coverage
  tests/integration/services/RecoveredFailuresTest.php`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and verify they pass,** plus
  `AnalyticsServiceTest` and the analytics API tests.

### Task 2: Dashboard, job page, job list, API

**Files:**
- Modify:
  - `controllers/SiteController.php`: `failedJobs` lists only unrecovered
    failures; `recoveredFailures24h` is a count; `jobs_today_failed`
    counts unrecovered failures only
  - `views/site/index.php`: the red panel only for unrecovered failures; a
    muted line "N failed jobs of the last 24 hours were followed by a
    successful run of the same template", linking to
    `/job/index?status=failed&recovered=1`
  - `views/job/view.php` and `controllers/JobController.php`: the note
    "A later run of this job template succeeded: #N", linked
  - `views/job/index.php`: a muted "recovered by #N" badge, through one
    `recoveredBy()` call per page
  - `models/JobSearchForm.php`: the filter `recovered`
  - `controllers/api/v1/JobsController.php` and the job serialization:
    the field `recovered_by_job_id`, the filter `recovered`
  - `web/openapi.yaml`
- Test:
  - `tests/integration/controllers/SiteControllerTest.php` (extend)
  - `tests/integration/controllers/JobControllerTest.php`
  - `tests/integration/controllers/api/v1/JobsControllerTest.php`
  - `tests/unit/models/JobSearchFormTest.php`

**Interfaces:**
- **Consumes:** Task 1.

- [ ] **Step 1: Write the failing tests:**
  - `testARecoveredFailureLeavesTheRedPanel`;
  - `testTheDashboardLineCountsOnlyTheLast24Hours`;
  - `testJobsTodayTurnsRedOnlyForUnrecoveredFailures`;
  - `testTheJobPageNamesTheRecoveringJob`;
  - `testTheListFiltersRecovered`;
  - `testTheApiReturnsRecoveredByJobId`;
  - `testTheApiFilterIgnoresInvalidValues`.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and verify they pass.**

### Task 3: E2E and docs

**Files:**
- Test: `tests/e2e/tests/site/dashboard-recovered.spec.ts` (new), and
  `tests/e2e/tests/jobs/*`, extended for the note and the badge
- Modify: `commands/E2eController.php`, seeding an e2e- template with a
  failed job and a later successful one (idempotent, e2e- prefix)
- Docs:
  - `docs/updating.md`: the release section
  - `README.md` or the docs that describe the dashboard
  - the analytics docs: MTTR ignores dry runs

- [ ] **Step 1: Write the failing specs:**
  - admin, operator and viewer each see the recovered failure left out of
    the red panel, the muted line, the job page note and the list badge;
  - the list filter works.
- [ ] **Step 2: Run them and verify they fail** (seed, run, teardown as in
  the other plan's Task 13).
- [ ] **Step 3: Implement the seed and the docs.**
- [ ] **Step 4: Run them and verify they pass.**

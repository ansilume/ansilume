# CLAUDE.md

## Purpose

Ansilume is a self-hostable automation platform built with **PHP 8.2+** and **Yii2**. It manages inventories, credentials, projects, and job templates, and runs **Ansible playbooks** through a web UI with RBAC, execution history, logging, and auditability. Comparable to AWX, Semaphore, or Rundeck.

This file tells Claude how to work inside this repository.

---

## Current State

The core platform is functional. The following capabilities are implemented and working:

- User authentication with optional TOTP 2FA
- RBAC with roles, permissions, and team-based access
- Projects (git-backed and manual) with sync
- Inventories (static, file-based, dynamic) with `ansible-inventory` parsing, and approval rules on inventories: a gated inventory gates every job template that uses it
- Credentials (SSH key, password, vault, token) with encrypted storage
- Job templates with survey fields, extra vars, verbosity, become, forks, limits, tags
- Job launch, execution, and real-time log streaming
- Runner groups with token-based runner registration, managed by admins
- Cron-based schedules for automated job execution
- Workflows (job, approval and pause steps with success/failure/always branches) and approval rules: admins manage and assign rules, change what a gated job template runs, and change the steps of workflows with an approval step or a gated job step; a rule on an inventory gates every job template that uses it, a launch waits for one rule, and only admins change a gated inventory; a runner starts a job whose job template or inventory has a rule only once the job was approved; nobody approves their own launch (a schedule runs as the user who last changed what or when it runs, or enabled it), the maintenance run applies approval timeouts, and a closed approval gate ends a run as failed unless its On Failure branch continues it
- Workflow steps placed by position (1..n, the run order) and added, edited, moved and removed one at a time in the web UI and the API (`WorkflowStepWriteService`), keeping their ID; the steps table names where every branch leads (`WorkflowRouteMap`); a run that waits says what for (Awaiting Approval, Paused, Queued: derived, the stored statuses stay) and links its approval request
- Email notifications on job success/failure
- Inbound triggers (a template's trigger token, sent in a header) and outbound webhooks
- REST API (v1) for projects, inventories, credentials, templates, jobs, schedules
- Audit logging for security-relevant actions
- Job artifacts and host summaries via Ansible callback plugin
- Selftest playbook for runner health verification
- Metrics endpoint for monitoring
- Docker-based deployment

Focus is now on **hardening, operational polish, and extending** the existing platform — not greenfield building.

---

## Primary Technology Stack

- **PHP 8.2+** with strict typing
- **Yii2** framework
- **MySQL / MariaDB**
- **Redis** for queue and cache
- **Composer** for PHP dependencies
- **Docker / docker compose** for development and deployment
- **Ansible** executed by isolated runner processes, never from web requests

Prefer boring, stable, well-understood technologies over trendy ones.

---

## Architecture Principles

1. **Thin controllers, rich services**
   - Controllers orchestrate requests and responses only.
   - Business logic belongs in service classes under `services/`.

2. **No Ansible execution in the request thread**
   - Web requests create job records.
   - Jobs are executed asynchronously by runner workers.
   - Job state is persisted and visible in the UI.

3. **Clear separation of concerns**
   - Models handle persistence and validation.
   - Services handle workflows.
   - Queue workers handle execution.
   - Views stay simple.

4. **Default to secure behavior**
   - Validate all input.
   - Escape all output (`Html::encode()`).
   - Restrict access by role.
   - Never expose secrets in logs or HTML.

5. **Idempotent and recoverable jobs**
   - Job records survive worker restarts.
   - State transitions are explicit.
   - Failures are visible and debuggable.

6. **Auditability**
   - Important actions are traceable via `AuditService`.
   - Changes to credentials, templates, launches, and permissions are attributable to a user.

---

## Optimization Priority

When making implementation choices, optimize in this order:

1. Correctness
2. Security
3. Maintainability
4. Operational clarity
5. Simplicity
6. Performance
7. Developer convenience

Do not trade correctness or safety for speed.

---

## Domain Language

Use consistent naming in code and UI.

- **Project**: a source of automation content, typically a git-backed repository
- **Inventory**: hosts and groups used by Ansible
- **Credential**: SSH key, username/password, vault secret, token, etc.
- **Job Template**: reusable launch definition for a playbook execution
- **Job**: one concrete execution of a template
- **Runner**: background process that claims and executes jobs
- **Runner Group**: logical grouping of runners; a job template names the runner group whose runners run its jobs, and each runner authenticates with a token of its own
- **Schedule**: cron-based automatic execution of a job template; runs as the user who last changed what or when it runs, or enabled it ("Runs as", `created_by`)
- **Trigger**: inbound endpoint (`/trigger/...`) that launches a job or workflow template with the template's trigger token, sent in the `X-Trigger-Token` header
- **Webhook**: outbound HTTP delivery of job events to an external URL
- **Artifact**: output produced by a job (host summaries, structured data)
- **Audit Log**: immutable record of meaningful user/system actions
- **Notification**: email alert triggered by job completion (success/failure)
- **Survey Field**: configurable launch-time parameter on a job template
- **Workflow Template**: ordered job, approval and pause steps with success/failure/always branches
- **Workflow Job**: one run of a workflow template (the UI's Workflow Jobs; no longer "Executions"); each step it executes is a workflow job step
- **Position**: a step's place 1..n in its workflow, by (`step_order`, `id`): the run order, which every page and message uses ("3. Deploy", 'step 3 "Deploy"'; a step's ID is "step ID 43"); `step_order` is an internal sort key, shown on no page, that every add, move and remove renumbers
- **Start Step**: position 1, where every run starts
- **Branch**: a step's On Success, On Failure or Always: empty (null) goes on with the next position (after the last step the run ends with the step's outcome), 0 ends the workflow (the run succeeds after a success, fails otherwise), a step ID leads to that step, one at the same or an earlier position is a loop (↺), and one that no longer exists fails the run
- **Waiting state**: Awaiting Approval, Paused or Queued (`waiting_for`: `approval`, `resume`, `runner`) of a run and its current step; derived for display and the API, never stored: a waiting run is stored as `running`
- **Approval Rule**: who must approve a launch (approver list, required approvals, timeout); gates a job template, an inventory (every job template that uses it) or a workflow approval step; a launch waits for one rule; managed and assigned by admins (`approval-rule.update`)
- **Gated Inventory**: an inventory with an approval rule; every launch of every job template that uses it waits for that rule; only admins (`approval-rule.update`) change it, every field but its name and description (`GatedInventoryLock`), delete it, give a job template this inventory or change the job templates that use it
- **Gated Job Template**: a job template whose launches wait for an approval rule: its own, or its inventory's (`JobTemplateGate`); a job template on a gated inventory has no rule of its own or the same rule; only admins (`approval-rule.update`) change what it runs, every field but its name and description (`GatedJobTemplateLock`); a job step that uses one is a gated job step
- **Approval Request**: the pending decision on one job that waits for approval (`pending_approval`); records what required it (`required_by`: `job_template`, `inventory` or `approval_step`); never decided by the user who launched the job

Do not invent multiple names for the same concept.

---

## Repository Structure

```
ansible/              Callback plugin, selftest playbook
assets/               Yii2 asset bundles
bin/                  Scripts (release)
commands/             Yii2 console commands (runner, worker, schedule, setup, health)
components/           Reusable framework components
config/               Environment-aware configuration (web, console, test, common)
controllers/          HTTP controllers (web UI + api/v1/ + api/runner/)
deploy/               Deployment artifacts
docker/               Dockerfiles, entrypoints, nginx config
helpers/              Utility classes (FileHelper)
jobs/                 Async job handlers (SyncProjectJob)
mail/                 Email templates (job notifications, password reset)
migrations/           Forward-only database migrations
models/               ActiveRecord models and form models
services/             Business logic (15+ service classes)
tests/                Unit + integration tests, mirroring src structure
views/                Yii2 view templates
web/                  Web root (index.php, assets)
```

---

## Coding Rules

### General
- Write clear, readable, production-oriented PHP.
- Prefer small, focused classes and methods.
- Avoid clever abstractions unless they clearly reduce complexity.
- Favor explicit code over magic.
- Do not introduce a new dependency unless it clearly pays for itself.

### Scrutinizer-CI compliance
All code must pass Scrutinizer-CI without issues. Proactively avoid common findings:
- **Class complexity**: Keep classes focused. If a class grows beyond ~15 methods, extract a separate class. Never combine unrelated concerns (e.g. filesystem scanning + git operations).
- **Method complexity**: Keep cyclomatic complexity per method low (target A rating). Extract helper methods for branches, loops, and early returns.
- **Nullable types**: When a model property is nullable (e.g. `$model->scm_url`), cast explicitly before passing to methods that expect non-null (`(string)$model->scm_url`). Scrutinizer cannot see guards across method boundaries.
- **Casts**: No space between cast and value: `(string)$var`, `(int)$var`, not `(string) $var`.
- **PHPDoc array shapes**: Use explicit keys (`array{0: string, 1: int}`) not positional (`array{string, int}`).
- **Return type analysis**: Avoid extracting methods where the return type depends on pass-by-reference semantics (e.g. `stream_select`) — Scrutinizer's static analysis cannot follow these correctly.
- **Spacing**: No alignment spacing. Single space around `=` and `=>`.
- **No numeric literal separators**: write `1048576`, not `1_048_576`. Scrutinizer's parser fails on them and skips the whole file. `tests/unit/config/ScrutinizerConfigTest.php` enforces this for every analysed file.
- **Scrutinizer only analyses**: `.scrutinizer.yml` is the complete config (the settings on scrutinizer-ci.com stay empty) and runs PHP/JavaScript analysis and PSR-12, nothing else. Never add services, PHPUnit, migrations or `.env` setup there; its services and toolchain broke builds for months, and every test already runs in GitHub Actions. Read `docs/ci.md` before changing `.scrutinizer.yml`; `ScrutinizerConfigTest` enforces the rules.

### Formatting
- **No alignment spacing.** Always use exactly one space around `=` and `=>`. Never pad with extra spaces to align columns. Scrutinizer and phpcs enforce this.
- **No `@` error suppression operator.** Use explicit checks (`if (file_exists(...))`) instead.
- Follow PSR-12 strictly. The test suite enforces this via phpcs.

### PHP
- Use strict typing (`declare(strict_types=1)`) in every PHP file.
- Add type hints for method arguments and return values.
- Avoid static god-classes.
- Prefer constructor injection or explicit dependency wiring over hidden globals.
- Keep methods short enough to understand without scrolling.

### Yii2
- Use Yii2 conventions unless there is a strong reason not to.
- Keep controllers thin.
- Use form models for user input scenarios when ActiveRecord is not the right boundary.
- Use transactions when multiple writes must succeed together.
- Centralize repeated policy checks and workflow logic.

### Database
- Every schema change must be made through migrations.
- Never edit old migrations after they are applied; add a new migration instead.
- Add indexes deliberately.
- Model state explicitly rather than encoding meaning in nullable fields.
- Lock order: approval_request before job (`ApprovalSettlement`); the job_template row FOR UPDATE before the inventory rows LOCK IN SHARE MODE, read in one statement in ascending ID order (`JobLaunchService`, `GatedJobTemplateLock`, `JobTemplateGate::readInventoryRules()`); an inventory save locks only its own row, first, and never takes a locking read of job_template (`InventoryWriteService`).

### Frontend / Views
- Keep UI utilitarian, clean, and operator-friendly.
- Favor clarity over flashy design.
- Always escape output with `Html::encode()`, even for seemingly static strings.
- Never leak sensitive values to the browser.
- A script's fetch() asks for JSON, whatever its method (`headers: { 'Accept': 'application/json' }`, and `credentials: 'same-origin'` for a GET), so that signed out it is refused with 403 instead of being sent to the login page, which for a GET also keeps the fetched address to return to after the next sign-in; it handles a refusal (401, 403) before it reads the answer as JSON, and treats any other answer that is not OK as a failure. `tests/unit/views/FetchAsksForJsonTest.php` enforces the header for every fetch( in views/ and web/js/.

---

## Security Requirements

These are mandatory.

1. Never commit secrets.
2. Never log raw credentials, private keys, vault secrets, tokens, or decrypted secret material.
3. Redact secrets in logs and UI: never echo stored secrets back, and show password survey answers, notification channel secrets (`NotificationTemplate::SECRET_CONFIG_KEYS`, `SECRET_CONFIG_MAPS`) and the webhook signing secret only as `$encrypted$` (`SurveySecrets::PLACEHOLDER`); a form or API request that sends `$encrypted$` back keeps the stored value. A repository URL is shown and logged without the credentials of its userinfo (`GitSyncDiagnostics::redactUrl`), and so is every text a sync stores: on the project page, in its sync output and errors, in the logs, in the job log and in notifications; only the API and the edit form show it in full, to those who may update the project.
4. Protect all state-changing actions with authorization checks.
5. Use CSRF protection for browser forms.
6. Validate and sanitize all user-controlled input.
7. Treat git repositories, playbook paths, extra vars, and shell arguments as untrusted input.
8. Avoid shell interpolation where possible.
9. When shell execution is required, escape arguments safely and keep commands auditable. Pass a URL, branch or other value a user controls to git after `--`, where git cannot read it as an option; `git pull` hands its refspecs on to `git fetch` without `--`, so fetch and merge instead (`ProjectService::gitPull()`, `RunnerProjectSync`).
10. Runner processes must execute with the least privilege practical.
11. Mark every parameter that carries a plaintext secret, a token, credentials, survey answers, static inventory content, the runner's claim payload or job output with `#[\SensitiveParameter]`, so stack traces in logs and error pages leave its value out. `tests/unit/SensitiveParametersTest.php` lists every mark (`MARKED`) and requires them per class (`MUST_BE_MARKED`): extend both when you add such a parameter.
12. Approval gates fail closed: only admins (`approval-rule.update`) manage approval rules and assign them to job templates and inventories, change what a gated job template (a job template with a rule of its own or on a gated inventory, `JobTemplateGate`) runs, every field but its name and description, change a gated inventory (an inventory with a rule), every field but its name and description, delete it and give a job template such an inventory (`GatedInventoryLock`, `GatedJobTemplateLock`), and change the steps of a workflow with an approval step or a gated job step (a job step whose job template is gated), whether a step is added, edited, moved or removed, and give a job step a gated job template; a launch waits for one rule: a job template on a gated inventory has no rule of its own or the same rule, and a launch that meets two different rules is refused before anything is written; a runner starts a job whose job template or inventory has a rule only once the job was approved, and fails a job whose inventory is gone or names no hosts before it runs (`JobClaimService`); the user who launched a job never decides on its approval (`ApproverEligibility`). The launcher is the user who chose what runs as far as Ansilume controls it: a schedule runs as the user who last changed what or when it runs, or enabled it, and only admins change a gated job template, a gated inventory or the steps of a workflow with an approval step or a gated job step. The project (repository, branch, SCM credential) and the credentials a gated job template uses keep their own permissions, and so does its inventory while the inventory has no rule, so whoever changes them is not the launcher and may approve; whoever may create or change a project can make its sync present any token or username and password credential to a server of their choosing, as credentials are not team-scoped; and a gated file or dynamic inventory takes its hosts from its project's repository when the job runs, so whoever may change that project or push to its repository changes them: documented limits until project and credential locks (`docs/updating.md`, "What an approval rule does not stop" of 2.12.0) that new code must not widen. Who may approve follows the users' roles, team memberships and the teams' projects, so whoever may change those (by default only admins) changes who may approve without `approval-rule.update`: a documented limit too (`docs/updating.md`, "Known limits" of 2.11.0); for the rule of an inventory too, the job template's project counts. A launch whose approval could never be granted is refused before anything is written.
13. Signed-out visitors reach only the login, password-reset and error pages, the trigger endpoint and the health checks (`/health`, and `/metrics` for monitoring): the default role applies to signed-in users only (`app\components\AuthManager`). A new page that a signed-out visitor must reach goes on the public list of `tests/integration/controllers/GuestAccessTest.php`, with its reason. In dev mode (`YII_ENV=dev`) `config/web.php` also loads the debug and gii modules, which everyone who reaches the stack can open, signed out too: `GuestAccessTest` runs with `YII_ENV=test` and does not see them, so a dev stack must never be reachable by others. `config/debug.php` censors the secrets its request panel would keep, but not the URL of a request (`tests/unit/config/DebugCensorTest.php`): a new form field or `.env` value that carries a secret goes on its list.
14. Only a superadmin sets or clears a user's superadmin flag, and a role is assigned only by a superadmin or by a user who holds every permission the role grants, its child roles and the default role included (`app\services\RoleGrantPolicy`); the admin role (`RoleGrantPolicy::ADMIN_ROLE`), which gives every team-restricted project on top of its permissions, and a role that contains it, only by a superadmin or a user who holds the admin role (`RoleGrantPolicy::ADMIN_ROLE_REFUSED`); keeping a role the user has assigns nothing. `user.created` and `user.updated` record `roles` and `is_superadmin` as `{from, to}` (`app\services\UserPrivileges`). A user who is no superadmin changes or deletes another user (the Edit User form, opened or saved, the status toggle, Delete, `PUT` and `DELETE /api/v1/users/{id}`) only when that user is no superadmin, they may assign every role assigned to that user and, unless they hold the admin role, their teams give them every team-restricted project the user's teams give the user, with at least the same role (`app\services\UserChangePolicy`); otherwise the answer is 403 (`UserChangePolicy::refusalOf()`: `CHANGE_REFUSED`, `ADMIN_ROLE_CHANGE_REFUSED` where the admin role is all they lack, or `TEAM_ACCESS_REFUSED`), after the 404 and before every other check, and the pages offer no Edit, toggle or Delete. A new action that writes another user's account (password, email, username, status, TOTP, auth source, API tokens) asks `UserChangePolicy::refusalOf()` first. The only active superadmin edits their own account; only a save that would clear their flag or deactivate them, and their deletion, are refused (`UserController::ONLY_SUPERADMIN`, `UsersController::ONLY_SUPERADMIN_DEMOTED`, `ONLY_SUPERADMIN_DEACTIVATED`, `ONLY_SUPERADMIN_DELETED`); only active superadmins count (`User::activeSuperadminExists()`), an inactive one may be demoted, deactivated or deleted, and `php yii setup/admin` creates a superadmin while no active superadmin exists (`SetupController::ACTIVE_SUPERADMIN_EXISTS` otherwise). A user who is no superadmin creates a role only with permissions they hold, and changes or deletes a role (the Edit Role form, opened or saved, Delete, `PUT` and `DELETE /api/v1/roles/{name}`) only when they may assign it, adding to it only permissions they hold (`app\services\RoleChangePolicy`, which `RoleService` takes for every write); otherwise the answer is 403 (`RoleChangePolicy::CHANGE_REFUSED`) after the 404 and before every other check, or `RoleChangePolicy::PERMISSION_REFUSED` for a permission they do not hold (an error of the Permissions field on the web, a 403 of the API after its 422s), and the pages offer no Edit or Delete for such a role and disable the permissions they do not hold. New code that writes a role (its permissions, description or existence) goes through `RoleService` with a `RoleChangePolicy`. Team management is administrative: whoever may change a team's members or projects (the admin role or the superadmin flag: `TeamController`, `TeamsController`) decides who reaches which team-restricted project, and a project that no team has is open to every signed-in user (`app\services\ProjectAccessChecker`), so removing a project's last team opens it: a documented limit (`docs/updating.md`, "Known limits" of 2.11.0) that new code must not widen.
15. An account deactivated in Ansilume (the status toggle, the Edit User form, `PUT /api/v1/users/{id}`) stays inactive whatever the directory says: an LDAP sign-in or `ldap/sync` activates again only an account that `ldap/sync` deactivated because the directory no longer had the user (`user.ldap_sync_disabled`), and every other change of an account's status clears that mark.
16. Only admins register, move and delete runners, regenerate runner tokens, and create or change runner groups (`runner-group.create`, `runner-group.update`; the operator role no longer holds them). A runner receives the decrypted credentials, the inventory and the extra vars of every job of its group, whatever the team, approved jobs included, so whoever registers a runner or holds a runner token can take over those jobs. A custom role with these permissions, and whoever holds the bootstrap secret (`RUNNER_BOOTSTRAP_SECRET`), can do the same: documented limits (`docs/runners.md`). New code that issues a runner token or puts a runner into a group requires `runner-group.update`.

If a proposed implementation is convenient but weakens security, reject it and choose the safer path.

---

## Ansible Execution Rules

- Never execute Ansible directly from a web controller.
- Use a persisted job record before execution starts.
- The claim (`JobClaimService`, `JobClaimGate`) fails a job before execution, before any credential is decrypted, when its job template, the inventory it was launched with or the template's current inventory has an approval rule and the job was not approved (its request approved, or timed out with the approve action), and when its inventory is gone or names no hosts (a static one without content, a file one without a source path) or its launch record names none; the claim's job.started entry stays, and job.finished records `claim_refusal`; the claim payload a runner receives stays the same.
- Build a deterministic runner payload from project + inventory + credential + template + launch vars.
- Store stdout/stderr incrementally via the callback plugin.
- Record start time, end time, exit code, final status, and launcher identity.
- Job statuses: `pending`, `queued`, `running`, `succeeded`, `failed`, `canceled`, `timed_out`, `pending_approval`, `rejected` (`Job::statuses()`).
- Keep runner implementation replaceable for future containerized or remote execution.

---

## Data Model

Core entities (all backed by ActiveRecord models and migrations):

- `user`, `auth_assignment`, `auth_item` — authentication and RBAC
- `team`, `team_member`, `team_project` — team-based access
- `project` — git or manual automation content source
- `inventory` — static, file, or dynamic host definitions; an optional approval rule (`approval_rule_id`) makes it a gated inventory
- `credential` — encrypted secrets (SSH, password, vault, token)
- `job_template` — reusable playbook launch configuration
- `job` — concrete execution instance
- `job_log` — incremental execution output
- `job_artifact`, `job_host_summary`, `job_task` — structured execution results
- `runner_group`, `runner` — execution infrastructure
- `schedule` — cron-based automation
- `workflow_template`, `workflow_step` — workflow definitions, a step's position derived from (`step_order`, `id`); `workflow_job`, `workflow_job_step` — workflow runs and their step executions, which keep the step's name and type when the step is removed
- `approval_rule`, `approval_request`, `approval_decision` — approval gates, their requests and the votes on them; a request records what required it (`approval_request.required_by`)
- `webhook` — outbound event deliveries (inbound triggers use `trigger_token` on `job_template` and `workflow_template`)
- `audit_log` — immutable action trail
- `api_token` — API authentication

Avoid opaque JSON unless it is genuinely schema-flexible (extra vars, artifact metadata).

---

## Testing

The test suite is split into independent scripts under `bin/` that can run in parallel:

| Script | Contents |
|--------|----------|
| `bin/tests-lint.sh` | Syntax, strict_types, security, XSS, CSRF, offline, migrations, consistency, composer, OpenAPI, ansible-lint, shell checks |
| `bin/tests-style.sh` | phpcs PSR-12, php-cs-fixer, PHPMD, PHPCPD |
| `bin/tests-phpstan.sh` | PHPStan static analysis |
| `bin/tests-phpunit.sh` | PHPUnit unit + integration tests, coverage, migration end-to-end |
| `bin/tests-e2e.sh` | Playwright E2E browser tests |

`bin/tests.sh` runs all suites sequentially for manual use.

Combined, the suites cover:

- PHPUnit (unit + integration tests)
- Playwright E2E browser tests (full UI walkthrough per role)
- `declare(strict_types=1)` enforcement
- PHP syntax check (excluding `vendor/`, `docker/`)
- phpcs PSR-12 compliance (strict, no excuses)
- PHPMD (cyclomatic complexity, unused code)
- PHPCPD (copy-paste detection)
- PHPStan static analysis
- XSS / output escaping audit
- `@` error suppression detection
- Controller consistency checks (RBAC, CSRF, base class)

### PHPStan level strategy

The long-term goal is to reach **PHPStan level max (9)** with zero errors. Levels are raised incrementally — every level increase must be accompanied by fixing all new findings before merging. The current enforced level is tracked in `phpstan.neon`. When adding new code, always write PHPDoc array shapes (`@param array<string, int>`, `@return array{key: type}`) and precise return types so that higher levels pass without rework.

Always run the full test suite — never partial or `--fast`.

### Coverage requirement

Every new feature or change must have **100% test coverage**. Every public method, every branch, every error path. No exceptions, no "I'll add tests later".

### E2E browser tests are mandatory

Every feature that touches the UI **must** be covered by Playwright E2E tests in `tests/e2e/tests/`. This is not optional and not separate from the coverage requirement — it is part of it.

- When adding a new controller or view, add matching `*.spec.ts` files covering the happy path, validation errors, and RBAC (admin/operator/viewer).
- When modifying an existing controller or view, extend the existing spec files so the new behavior is exercised in a real browser.
- When adding a new permission or role check, add an RBAC spec that proves the right roles are allowed and the wrong roles are blocked (403 or hidden UI).
- When seeding new entities is needed, extend `commands/E2eController.php` (keep it idempotent, always prefixed `e2e-`).
- PHPUnit coverage alone does **not** satisfy Definition of Done for UI features. The test is whether a real browser click-through works end-to-end.

### Regression tests for every bug

Whenever a bug is found — reported by a user, caught in production, discovered in review, or hit during development — you must write a test that would have caught it **before** shipping the fix. No bug gets fixed without a test that locks in the fix.

- **PHPUnit regression test** when the bug lives in a service, model, API, or other PHP layer.
- **Playwright regression test** when the bug manifests in the UI, in a form submission, in navigation, or in an RBAC/permission check visible to the user.
- The test must **fail on the unfixed code** and **pass on the fixed code**. Verify this explicitly — don't just assume.
- Name or comment the test so it is clear it was written for a specific bug (e.g. "regression: schedule with empty cron was accepted"). This makes future refactors safe.
- If the bug was caused by a missing edge case, add tests for the full class of edge cases, not just the one instance.

The rule is simple: **a bug may only happen once**. The second time is a test failure, not an incident.

### What makes a good test

Tests must verify that **the application works and does not break**. Each test should answer: "What would go wrong if this code had a bug?"

**Write tests that:**
- Verify real behavior end-to-end (service creates a job, API returns correct response, permission is denied)
- Cover the happy path AND the failure paths (invalid input, missing records, permission denied, network errors)
- Catch regressions — if someone changes the code, a test must fail
- Test authorization (correct role can access, wrong role is rejected)
- Test validation (invalid input is rejected with the right error)
- Test state transitions (job goes from pending to running to succeeded/failed)
- Test every API endpoint (correct response codes, response structure, auth required, invalid input rejected)
- Use realistic data, not trivial "assert 1 === 1" stubs

**Do NOT write tests that:**
- Only test that a method exists or returns a type
- Assert trivial getters/setters with no logic
- Duplicate what the framework already guarantees (e.g. testing that ActiveRecord saves a field)
- Test implementation details instead of behavior (e.g. "method X was called 3 times")
- Pass with any return value (e.g. `assertNotNull` when the real assertion should check the value)

---

## Definition of Done

A task is not done unless:

- The code works end-to-end for the intended use case
- Authorization is correct
- Validation is present
- Errors are handled clearly
- Migrations are included when needed
- **Tests with 100% coverage** are added — happy path, error paths, authorization, validation
- **Playwright E2E tests** exist for any UI-facing change — per-role click-through plus RBAC
- **API endpoint** exists for the feature (no UI-only functionality)
- **API endpoint is tested** — response codes, response structure, auth, validation, error cases
- **If this work fixes a bug**: a regression test exists that fails without the fix and passes with it
- No secrets are exposed
- Naming is consistent with the domain language
- The code fits the existing structure and style
- Basic operator UX is considered

---

## How Claude Should Work

When given a feature request:

1. Understand the user goal
2. Inspect the current codebase structure
3. Identify affected models, services, controllers, views, migrations, and tests
4. Propose the simplest architecture-compatible implementation
5. Implement in small, coherent steps
6. Summarize what changed, risks, and follow-up work

If the request is underspecified, make reasonable assumptions that fit this file and document them briefly. Do not get stuck asking unnecessary questions when a sensible default exists.

### Dev checkout AND prebuilt image — both paths must stay working

Every change has to run correctly for **both** deployment paths. They diverge in subtle ways that break silently if only one is considered:

- **Dev / git checkout** — `docker-compose.yml` with `.:/var/www` bind-mounted into `app`, `queue-worker`, `schedule-runner`, `runner-1`, `runner-2`. Containers are built locally from `docker/php/Dockerfile` (with `USER_ID`/`GROUP_ID` build args that remap `www-data` to the host UID). Code is read live from the host; `composer install` runs in the entrypoint on every start. Operators can't pick up updated `Dockerfile` or `entrypoint.sh` changes without `docker compose up -d --build`.
- **Prebuilt images** — `docker-compose.prebuilt.yml` pulls `ghcr.io/ansilume/ansilume:latest`, `ghcr.io/ansilume/ansilume-nginx:latest`, `ghcr.io/ansilume/ansilume-runner:latest`. The runner image has its **own** `/var/www` and no bind mount, so anything the server writes to `/var/www/runtime/projects/` is **not visible** to the runner; the runner must clone the project itself. Operators pick up updates via `docker compose pull && docker compose up -d`, not `up --build`.

Keep these in sync:

- When `commands/RunnerController.php` changes, check `docker/runner/Dockerfile` still ships what the runner needs (packages, collections), and `docker/php/Dockerfile` / `Dockerfile.prod` match for the shared container image.
- When `docker/php/Dockerfile` gains a PHP extension, `Dockerfile.prod` installs it too, unless it serves only the tests, as pcov does (`tests/unit/docker/PhpExtensionParityTest.php`, `DEV_ONLY`): the released app image `ghcr.io/ansilume/ansilume` had no ldap extension up to 2.10.1.
- When the server payload grows a new field (`JobClaimService::buildExecutionPayload`), **the runner must consume it** and **no runner can legitimately assume the field is always present** — old runner images may still connect to a new server after upgrade.
- When `docker-compose.yml` gets a new service or volume, check if `docker-compose.prebuilt.yml` needs the same treatment (and vice-versa). The `web_assets` volume that was missing from the prebuilt compose — letting nginx 404 on `/assets/<hash>/jquery.js` — is the canonical case of this failure mode.
- When a runtime directory gets a new ownership requirement (chown in entrypoint), make sure `docker/php/entrypoint.sh`, `docker/php/entrypoint-prod.sh`, and `docker/runner/entrypoint.sh` all agree.
- When changing a subprocess env contract (`GIT_CONFIG_*`, `ANSIBLE_*`), verify that both the server-side path (`ProjectService`) and the runner-side path (`RunnerController` → `GitEnvBuilder`) stay in sync — they serve the same operation from two places and must not drift.
- A sync of an existing checkout points its origin at the project's current URL before it fetches, on the server (`ProjectService::gitPull()`) and on runners (`RunnerProjectSync`): keep both paths doing so.

"Works on my dev checkout" is not a ship-gate. Before finishing a change that touches process startup, filesystem layout, subprocess env, or inter-container contracts, think through what the operator sees in both modes.

---

## Push Workflow

Do not run tests after every task — only when the user says **"PUSH IT"**.

When the user says "PUSH IT", follow this exact sequence:

1. **Always run every test suite as a subagent — never as a background `Bash` command.** Dispatch one `Agent` call (subagent_type=general-purpose) per `bin/tests-*.sh` script (excluding `bin/tests-common.sh`) in a single message so they run in parallel. Each agent runs its suite, captures the output, and reports back a concise pass/fail summary plus any failure detail. This keeps multi-megabyte test output out of the main context window.

   **Never** shortcut this by calling `./bin/tests-*.sh` via `Bash(run_in_background=true)` — the output still piles into context, and multiple failed suites produce enough noise to destabilise the session. If a single suite must be re-run mid-debug (e.g. after a targeted fix), that one re-run via Bash is fine, but the full-suite runs that gate a release are agents-only.

   Never use `--fast`.

2. **All suites must pass.** Zero errors, zero warnings. If any suite fails, fix the issue and re-run the failing suite(s) (again as a subagent) until clean.
3. **Commit** all changes.
4. **Ask the user via the `AskUserQuestion` tool** which release flavour they want. Single question, single-select, exactly four options (the picker auto-appends an `Other` fallback — that's fine, ignore it for typical use):
   - **PLAIN** — plain commit, no release. Action: `git push`.
   - **PATCH** — bug-fix release. Action: `./bin/release patch`, then `git push --follow-tags`.
   - **MINOR** — feature release. Action: `./bin/release minor`, then `git push --follow-tags`.
   - **MAJOR** — breaking-change release. Action: `./bin/release major`, then `git push --follow-tags`.

   Use `header: "Release type"` and a clear question like "Wie soll ich pushen?" / "How should I push this?". Do NOT ask via plain text — the picker is the source of truth so the answer is unambiguous and consistent across every PUSH IT cycle.
5. **After the push, check the pushed commit** once GitHub Actions and Scrutinizer have finished, with the commands in `docs/ci.md`, and report both results. A red build in either is fixed before new work starts.

Never push without all green test suites. Never skip asking about the release type.

---

## Preferred Implementation Style

### Prefer
- Service classes for business logic
- Action classes for discrete workflows
- Small helper objects for payload building and command composition
- Explicit DTO-like structures when passing execution data between layers
- Constants for state values
- Policy checks in reusable places

### Avoid
- Fat controllers
- Giant ActiveRecord models containing all business logic
- Duplicated authorization logic
- Hidden side effects in getters/setters
- Mixing HTML rendering, persistence, and process execution in one class
- Premature microservices

---

## Migration Strategy

- Create forward-only migrations
- Backfill carefully when new non-nullable columns are introduced
- Add indexes for common filters (status, template_id, project_id, created_at)
- Avoid destructive changes unless clearly required
- Prefer incremental schema evolution
- Seed migrations should be idempotent (check before insert)

---

## Logging and Observability

- Important lifecycle events are logged
- Job execution is inspectable from the UI (logs, artifacts, host summaries)
- Errors include operator-usable context
- Sensitive values are redacted
- Rule for all code, old and new: a database error (`yii\db\Exception`, or a `PDOException` that escaped Yii's wrapping) is logged with its class, SQLSTATE and driver error code only (`app\components\DbErrorSummary`), never with its message, which carries the SQL statement with its values. The same holds wherever an error is kept or shown: audit entries (such as the error of `notification.failed`), notifications and answers. Other exceptions may keep their message where it carries no SQL: `app\components\ErrorText` gives the message of any other error and the summary of a database error. When you touch code that logs `$e->getMessage()` of an exception that can be a database error, convert it. Errors that nothing catches are logged by `app\components\WebErrorHandler` (`config/web.php`) and `app\components\ConsoleErrorHandler` (`config/console.php`, `config/test.php`), and failed queue jobs by `app\components\QueueLogBehavior` (the queue's `as log` in `config/console.php`): for a chain that holds a database error they write what `app\components\ExceptionLogText` gives, never a message that can carry SQL or a Redis command with its values. Every log target is `app\components\RedactingFileTarget` (`config/web.php`, `config/console.php`, `config/test.php`), which writes an exception logged as an object whose chain holds a database error as `ExceptionLogText` gives it, so that the framework's own `Yii::error($e)` (such as `yii\db\Connection`'s failed rollback) cannot write its message; a log target of another kind needs the same redaction. Keep them wired in every config, and never log an exception that can be a database error as an object or as its string form yourself (`Yii::error($e)`, `(string)$e`): both carry its message and those of the exceptions it wraps, and no target can redact the string form; `ExceptionLogText::of()` gives its chain without them.
- A git command, git's output and any other text that can hold a repository URL pass through `GitSyncDiagnostics::redactUrl` before they are logged, stored or shown (`ProjectService`, `ProjectSyncProcessRunner`, `RunnerProjectSync`); a notification channel logs its HTTP code, never its URL
- User-facing and logged messages live in class constants (sprintf patterns), so the docs and tests can quote them
- Metrics endpoint available for monitoring
- Selftest playbook verifies runner health

---

## API and Extensibility

**Every feature must be available via the REST API.** The UI is one client — the API is the primary interface. When building a new feature, always implement the API endpoint alongside (or before) the UI. There must be no UI-only functionality.

Business logic lives in services, not controllers, so workflows are reusable by:

- REST API endpoints (`controllers/api/v1/`)
- Runner API endpoints (`controllers/api/runner/`)
- CLI commands (`commands/`)
- Cron schedules (`ScheduleService`)
- Inbound triggers (`TriggerController`)

### OpenAPI spec is mandatory

The canonical OpenAPI 3.1 spec lives at `web/openapi.yaml` and is served
publicly by nginx at `/openapi.yaml` (CORS-enabled). A Swagger UI container
(`docker compose up -d swagger`, port `8088` by default) consumes it and gives
operators an interactive API explorer.

The spec is part of the contract, not documentation that can lag behind:

- **Every new endpoint** (new route, new verb on an existing route) must be
  added to `web/openapi.yaml` in the same commit as the controller change.
- **Every change to an existing endpoint** — new field, renamed field,
  changed response shape, new status code, tightened validation — must be
  reflected in the spec in the same commit.
- **Every new schema** (request body, response body, nested object) must
  have a named entry under `components.schemas` and be referenced via `$ref`.
  Never inline request/response types when they are reused.
- **Examples are required** for non-trivial POST/PUT bodies so Swagger UI
  users can hit "Try it out" without guessing.
- **Secrets must never appear** in spec examples or default values —
  placeholders only (e.g. `"-----BEGIN OPENSSH PRIVATE KEY-----\n...\n..."`).
- **The spec version** (`info.version`) should track the application version
  and be bumped together with `./bin/release`.

A test in `bin/tests-lint.sh` validates that `web/openapi.yaml` parses as valid YAML
and resolves all `$ref`s. If you add an endpoint without updating the spec,
the suite will fail.

---

## Performance Guidance

Do not optimize prematurely. However:

- Avoid N+1 queries in common list pages
- Paginate large job history views
- Stream or chunk logs sensibly
- Keep queue workers resilient for long-running jobs
- Cache only where it meaningfully reduces load

---

## UI Guidance

The UI should feel like an operator console.

- Clear navigation
- Predictable forms
- Obvious launch flow
- Visible job status and timestamps
- Readable logs with ANSI color support
- Useful empty states
- Strong feedback on validation and failures

Prefer operational clarity over visual experimentation.

---

## Things Claude Must Not Do

- Do not rewrite unrelated parts of the codebase just for style
- Do not add unnecessary frameworks or front-end rewrites
- Do not replace Yii2 unless explicitly asked
- Do not introduce breaking schema changes casually
- Do not bypass permissions for convenience
- Do not store secrets in plain logs, HTML, JS, or fixtures
- Do not collapse architecture into a single monolithic controller/model
- Do not leave TODO-only implementations presented as complete
- Do not use alignment spacing (extra spaces before `=` or `=>`)
- Do not use `@` error suppression

---

## When Tradeoffs Are Needed

Prefer:

- boring over clever
- explicit over magical
- secure over convenient
- maintainable over short
- incremental over sweeping rewrites

---

## Development Commands

```bash
docker compose up -d          # Start development environment
bin/tests.sh                  # Full test suite sequentially (runs all bin/tests-*.sh)
bin/tests-lint.sh             # Run only lint/static checks
bin/tests-style.sh            # Run only code style checks
bin/tests-phpstan.sh          # Run only PHPStan
bin/tests-phpunit.sh          # Run only PHPUnit (unit + integration)
bin/tests-e2e.sh              # Run only Playwright E2E
./bin/release                 # Version bump + tag (run before push)
```

Inside the Docker container (via `docker compose exec php`):

```bash
php yii migrate               # Run pending migrations
php yii setup/init            # Initial setup (admin user, roles, demo data)
php yii runner/start           # Start a runner worker
php yii worker/listen          # Start queue worker
php yii schedule/run           # Execute due schedules
composer install               # Install PHP dependencies
```

---

## Roadmap Focus

### Completed

1. ~~**Hardening**~~ — security hardening, input validation, race conditions (v2.0.23)
2. ~~**Operational polish**~~ — relative timestamps, breadcrumbs, table filters, offline check (v2.0.24)
3. ~~**First-run reliability**~~ — quickstart pre-flight checks, diagnostics, health checks (v2.0.25)
4. ~~**API CRUD endpoints**~~ — all resources (Projects, Job Templates, Schedules, Runner Groups, Teams, Users, Webhooks, Audit Logs) have full REST API with tests and OpenAPI spec
5. ~~**Runner group assignment**~~ — runners can be moved between groups via web UI and REST API, with active-job guards and audit logging (v2.0.26)
6. ~~**Team scoping**~~ — multi-tenant resource isolation: projects assigned to teams with viewer/operator roles; all child resources (templates, inventories, jobs, schedules, approval requests) inherit access transitively; workflows belong to the projects of their job steps (seeing needs view, changing/launching/resuming/canceling operator access to every one; steps with a missing job template fail closed) via `WorkflowAccessChecker`; analytics and the dashboard are scoped; schedules (run as the user who last changed what or when they run, or enabled them), triggers (run as the user who generated the token) and workflow job steps re-check that user at launch time; enforced across web UI and REST API
7. ~~**LDAP/AD integration**~~ — optional Active Directory / OpenLDAP authentication as addon (local users remain alongside; password ops disabled for LDAP users; group-to-role mapping; auto-provisioning; `ldap/sync` console command for lifecycle reconciliation; admin diagnose endpoint). See `docs/ldap.md`.
8. ~~**Inventory-level approvals**~~ — approval rules on inventories (`approval_rule_id`): a rule on an inventory gates every launch of every job template that uses it, one rule per launch, only admins change a gated inventory and the job templates that use it, and runners start a gated job only once it is approved (v2.12.0)

### Next

1. **HA / scaling** — multi-worker, runner failover, queue reliability
2. **Advanced features** — artifact management
3. **Project and credential locks** — project settings (repository URL, branch, SCM credential, local path, vault source) and the credentials of gated work changed only by admins, and credentials scoped to teams (plan step 11), so that whoever changes them no longer approves the jobs they affect and a project's sync no longer presents a credential its user may not use; until then documented limits (security rule 12, `docs/updating.md` "What an approval rule does not stop" of 2.12.0)

### Completed (Advanced Features)

- ~~**Launch surveys**~~ — survey fields on job templates with editor UI, launch-time input, merge into extra vars, API support
- ~~**Relaunch/retry**~~ — re-launch finished jobs with same parameters (extra vars, limit, verbosity, check mode), UI button + RBAC

Strengthen the core before adding new surface area.

---

## North Star

Ansilume should be a dependable automation control plane for operators.

Every change should move toward:
- clearer execution flows
- safer credential handling
- better auditability
- easier maintenance
- more predictable operations

When in doubt, build the thing an infrastructure team would trust to use.

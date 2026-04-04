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
- Inventories (static, file-based, dynamic) with `ansible-inventory` parsing
- Credentials (SSH key, password, vault, token) with encrypted storage
- Job templates with survey fields, extra vars, verbosity, become, forks, limits, tags
- Job launch, execution, and real-time log streaming
- Runner groups with token-based runner registration
- Cron-based schedules for automated job execution
- Email notifications on job success/failure
- Webhooks for external triggering
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
- **Runner Group**: logical grouping of runners with shared token authentication
- **Schedule**: cron-based automatic execution of a job template
- **Webhook**: external trigger endpoint for launching jobs
- **Artifact**: output produced by a job (host summaries, structured data)
- **Audit Log**: immutable record of meaningful user/system actions
- **Notification**: email alert triggered by job completion (success/failure)
- **Survey Field**: configurable launch-time parameter on a job template

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
jobs/                 Async job handlers (RunAnsibleJob, SyncProjectJob)
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

### Frontend / Views
- Keep UI utilitarian, clean, and operator-friendly.
- Favor clarity over flashy design.
- Always escape output with `Html::encode()`, even for seemingly static strings.
- Never leak sensitive values to the browser.

---

## Security Requirements

These are mandatory.

1. Never commit secrets.
2. Never log raw credentials, private keys, vault secrets, tokens, or decrypted secret material.
3. Redact secrets in logs and UI via `CredentialService`.
4. Protect all state-changing actions with authorization checks.
5. Use CSRF protection for browser forms.
6. Validate and sanitize all user-controlled input.
7. Treat git repositories, playbook paths, extra vars, and shell arguments as untrusted input.
8. Avoid shell interpolation where possible.
9. When shell execution is required, escape arguments safely and keep commands auditable.
10. Runner processes must execute with the least privilege practical.

If a proposed implementation is convenient but weakens security, reject it and choose the safer path.

---

## Ansible Execution Rules

- Never execute Ansible directly from a web controller.
- Use a persisted job record before execution starts.
- Build a deterministic runner payload from project + inventory + credential + template + launch vars.
- Store stdout/stderr incrementally via the callback plugin.
- Record start time, end time, exit code, final status, and launcher identity.
- Job statuses: `pending`, `waiting`, `running`, `successful`, `failed`, `error`, `canceled`.
- Keep runner implementation replaceable for future containerized or remote execution.

---

## Data Model

Core entities (all backed by ActiveRecord models and migrations):

- `user`, `auth_assignment`, `auth_item` — authentication and RBAC
- `team`, `team_member`, `team_project` — team-based access
- `project` — git or manual automation content source
- `inventory` — static, file, or dynamic host definitions
- `credential` — encrypted secrets (SSH, password, vault, token)
- `job_template` — reusable playbook launch configuration
- `job` — concrete execution instance
- `job_log` — incremental execution output
- `job_artifact`, `job_host_summary`, `job_task` — structured execution results
- `runner_group`, `runner` — execution infrastructure
- `schedule` — cron-based automation
- `webhook` — external trigger endpoints
- `audit_log` — immutable action trail
- `api_token` — API authentication

Avoid opaque JSON unless it is genuinely schema-flexible (extra vars, artifact metadata).

---

## Testing

`./tests.sh` is the single source of truth for the test suite. It runs inside Docker and includes:

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

Always run the full `./tests.sh` — never partial or `--fast`.

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

---

## Push Workflow

Do not run `./tests.sh` after every task — only when the user says **"PUSH IT"**.

When the user says "PUSH IT", follow this exact sequence:

1. **Run `./tests.sh`** — the full test suite, never partial or `--fast`.
2. **All checks must pass.** Zero errors, zero warnings. If anything fails, fix it and re-run until clean.
3. **Commit** all changes.
4. **Ask the user**: plain commit (no release) or a release? If release, ask: `PATCH`, `MINOR`, or `MAJOR`?
   - **Plain commit**: just `git push`.
   - **Release**: run `./bin/release patch`, `./bin/release minor`, or `./bin/release major`, then `git push --follow-tags`.

Never push without a green `./tests.sh`. Never skip asking about the release type.

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
- Webhooks (`WebhookService`)

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

A test in `tests.sh` validates that `web/openapi.yaml` parses as valid YAML
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
./tests.sh                    # Full test suite (always run complete, never partial)
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

With the core platform functional, prioritize:

1. **Hardening** — edge cases, error handling, input validation gaps
2. **Operational polish** — better empty states, UX feedback, log rendering
3. **Team scoping** — proper multi-tenant project/resource isolation
4. **HA / scaling** — multi-worker, runner failover, queue reliability
5. **Advanced features** — artifact management, launch surveys, relaunch/retry

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

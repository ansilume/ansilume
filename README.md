<p align="center">
  <img src="ansilume.svg" alt="Ansilume" width="360">
</p>

<p align="center">
  <strong>Self-hosted Ansible automation platform</strong><br>
  Run playbooks, manage infrastructure, track every execution — all from a clean, intuitive UI.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white" alt="PHP 8.5">
  <img src="https://img.shields.io/badge/Yii2-Framework-007BEE?logo=yii&logoColor=white" alt="Yii2">
  <img src="https://img.shields.io/badge/MariaDB-10.11-003545?logo=mariadb&logoColor=white" alt="MariaDB">
  <img src="https://img.shields.io/badge/Redis-7.2-DC382D?logo=redis&logoColor=white" alt="Redis">
  <img src="https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white" alt="Docker">
  <br>
  <a href="https://scrutinizer-ci.com/g/ansilume/ansilume/?branch=main"><img src="https://scrutinizer-ci.com/g/ansilume/ansilume/badges/quality-score.png?b=main" alt="Scrutinizer Code Quality"></a>
  <a href="https://scrutinizer-ci.com/g/ansilume/ansilume/build-status/main"><img src="https://scrutinizer-ci.com/g/ansilume/ansilume/badges/build.png?b=main" alt="Build Status"></a>
  <a href="https://scrutinizer-ci.com/g/ansilume/ansilume/?branch=main"><img src="https://scrutinizer-ci.com/g/ansilume/ansilume/badges/code-intelligence.svg?b=main" alt="Code Intelligence Status"></a>
  <img src="https://img.shields.io/badge/License-Apache%202.0-green" alt="License">
</p>

---

<p align="center">
  <img src="docs/screenshots/dashboard.png" width="800" alt="Dashboard">
</p>

---

## Get started

**Requires:** Docker + Docker Compose (x86_64 or arm64)

```bash
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/quickstart | bash
```

The installer asks a few questions (deployment mode, database, port, admin credentials) and handles everything else automatically. → [Full installation guide](docs/installation.md)

---

## Overview

Ansilume /ˈæn.sɪ.luːm/ is a **production-ready, self-hostable automation control plane** built for infrastructure teams. It gives you a reliable web interface to manage and execute Ansible workloads — with full auditability, role-based access control, and zero execution in the web request lifecycle.

Think of it as a lightweight, self-hosted alternative to AWX or Semaphore — designed for teams that want **operational clarity** over feature bloat.

---

## Features

### Execution
- **Async, isolated execution** — jobs run on pull-based runner agents on their own network, never in the web request thread
- **Live job output** — streaming stdout/stderr with ANSI colors, per-task progress, and full play recap per host
- **Host summaries & task results** — structured results captured via a custom Ansible callback plugin
- **Job templates** — reusable launch configs with extra vars, verbosity, become, forks, limits, tags, and survey fields for launch-time input; answers to password fields are stored encrypted and shown as `$encrypted$`
- **Workflows** — chain job templates, approval steps and pause steps; place each step by position (at the start, at the end or after another step), add, edit, move or remove it in one action in the UI or the API, and see where every on-success / on-failure / always branch leads; a run that waits says what for (Awaiting Approval, Paused, Queued) and links its approval request; a rejected gate ends the run as failed unless an on-failure branch continues it, launch extra vars reach every job step, and runs keep their history when the steps change ([guide](docs/workflows.md))
- **Pull-based runners** — lightweight agents that self-register with the bootstrap secret, or start with a token an admin hands out; isolate execution from the control plane
- **Runner groups** — logical grouping of runners, each authenticating with a token of its own, with health tracking and a selftest playbook
- **Cancel & timeout** — in-flight jobs can be canceled; configurable per-template execution timeouts

### Inventory, projects & credentials
- **Git-backed projects** — sync playbooks from remote repositories, or manage content manually
- **Automatic ansible-lint** — lint results recorded on every project sync
- **Inventories** — static, file-based, and dynamic inventories parsed through real `ansible-inventory`; an admin can give one an approval rule, and every launch against it then waits for approval
- **Credentials** — SSH key, username/password, vault secret, and token types, all AES-256-CBC encrypted at rest
- **SSH key tools** — in-UI ed25519 key generation with algorithm/strength analysis for uploaded keys

### Automation & integrations
- **Scheduled jobs** — cron-based scheduling with next/last-run tracking and catch-up protection
- **Inbound webhooks** — launch job templates and workflows from external systems with a per-template trigger token, sent in the `X-Trigger-Token` header and managed in the UI or the API; a trigger runs as the user who generated its token and is refused once that user may no longer launch
- **Notifications v2** — email, Slack, Webhook, Telegram, and PagerDuty channels with a shared event catalog
- **REST API (v1)** — every feature exposed as an API; the UI is just one client
- **OpenAPI 3.1 spec** — canonical `web/openapi.yaml` + bundled Swagger UI at `:8088` for interactive exploration
- **Analytics dashboard** — job, workflow, approval, and runner statistics with time-window filters

### Access control & auditability
- **Custom role management** — built-in `viewer` / `operator` / `admin` plus user-defined roles with domain-grouped permission editor
- **Multi-tenant team scoping** — assign projects to teams with viewer or operator roles; all child resources (templates, inventories, jobs, schedules, approval requests) inherit access from their parent project, workflows from the projects of their job steps, and analytics and the dashboard count only what the user may see. Users only see resources belonging to their teams. Admins bypass all restrictions. Unscoped projects remain visible to everyone for backward compatibility
- **Two-factor authentication** — optional TOTP 2FA per user (Google Authenticator, Authy, 1Password, …) with bcrypt-hashed recovery codes and rate-limited verification
- **Password reset** — self-service reset via signed email link
- **Full audit trail** — every launch, configuration change, credential access, and permission edit recorded against an actor
- **Approval workflows** — gate sensitive job templates, inventories and workflow steps behind named approval rules with per-rule approver lists; rules are managed and assigned by admins, who alone change the steps of workflows with an approval step or a job step whose template has a rule, the hosts of a static inventory with a rule and the source of a file or dynamic one, and what a job template with a rule runs (every field but its name and description); a rule on an inventory gates every job template that uses it, one rule per launch; nobody approves their own launch, runners start a gated job only once it is approved, and timeouts apply every minute. A rule gates job templates and inventories, not hosts or playbooks: whoever may create inventories and job templates can reach the same hosts through ones without a rule, and whoever may change the project or credentials of a template with a rule, or its inventory while the inventory has no rule, changes what its jobs run. An approval step gates the runs of its workflow, not the job templates in it: whoever may launch those templates can launch them directly, or from another workflow without the step

### Operations
- **Air-gapped / offline ready** — all CSS, JavaScript, and fonts are bundled locally; the UI requires no outbound internet access and works fully offline in isolated or restricted networks
- **Monitoring endpoints** — [Prometheus and JSON metrics](docs/monitoring.md) for jobs, tasks, hosts, runners, queues, and infrastructure health
- **Health dashboard** — at-a-glance status of database, Redis, queue, runners, and migrations
- **Production deployment** — [Ansible role](docs/deployment.md) for automated install, upgrades, and rollbacks
- **Docker-native** — reproducible dev and prod stacks via `docker compose`, prebuilt multi-arch images (amd64 + arm64) on ghcr.io

---

## Screenshots

<table>
  <tr>
    <td><img src="docs/screenshots/job-details1.png" alt="Job detail"></td>
    <td><img src="docs/screenshots/job-details2.png" alt="Job output"></td>
  </tr>
  <tr>
    <td align="center"><em>Job detail — play recap per host</em></td>
    <td align="center"><em>Job output — full Ansible stdout</em></td>
  </tr>
  <tr>
    <td><img src="docs/screenshots/template-details.png" alt="Template"></td>
    <td><img src="docs/screenshots/project-details.png" alt="Project"></td>
  </tr>
  <tr>
    <td align="center"><em>Job template with webhook trigger</em></td>
    <td align="center"><em>Git project with lint results</em></td>
  </tr>
</table>

---

## Architecture

```
Browser ──→ Nginx ──→ PHP-FPM (Yii2) ──→ MariaDB
              ▲                     └──→ Redis (queue / cache / sessions)
              │                                ▲
              │ runner API (HTTP)              │
              │                          Queue Worker (project sync, lint)
     Runner Agent(s) ──→ ansible-playbook
     (own network, pull jobs)
```

| Layer | Role |
|---|---|
| `controllers/` | Thin HTTP controllers — orchestrate requests only |
| `services/` | Business logic — JobLaunchService, CredentialService, AuditService, … |
| `jobs/` | Async queue job handlers |
| `commands/` | Console commands (setup, runner, worker, health) |
| `models/` | ActiveRecord models + form models |
| `migrations/` | Forward-only schema migrations |

Runners are pull-based agents that poll the server for queued jobs and self-register on first start using `RUNNER_BOOTSTRAP_SECRET`. See [docs/runners.md](docs/runners.md) for setup and configuration.

---

## Security

- Credentials are AES-256-CBC encrypted at rest — raw secrets never appear in logs or HTML
- Answers to password survey fields are encrypted with the same key — pages, the API and the stored command line show `$encrypted$`; re-launches and schedules reuse them without showing them
- Channel secrets of notification templates (bot tokens, webhook URLs and header values, routing keys) show as `$encrypted$` on every page and in the API, and the signing secrets of outbound webhooks on their edit form; a save that sends `$encrypted$` back keeps the stored value. A project's page, its sync output and errors, the logs and the notifications show its repository URL without the credentials in it, and so does the API to those who may not update the project
- Runners get password answers in a `0600` file instead of on the command line and mask them in job output (best effort); older runners get them inline with the verbosity capped so that Ansible does not print them. Each job's answers file, task records and static inventory live in a directory of the job that only the runner's user can open, removed when the job ends
- TOTP secrets are AES-256-CBC encrypted at rest — the same key used for credentials
- RBAC roles: `viewer` (read-only) · `operator` (launch + manage) · `admin` (full access) — plus custom roles; approval rules are created, changed and assigned by admins only (`approval-rule.update`), and only admins change what a job template with an approval rule runs, and the hosts of a static inventory with one or the source of a file or dynamic one; only admins register, move and delete runners, regenerate runner tokens and create or change runner groups (`runner-group.create`, `runner-group.update`), as a runner receives the decrypted credentials of every job of its group
- Approvals: the user who launched a job never approves it, admins included, and only active approvers who hold `approval.decide` (or are superadmins) and may see the job count; a launch is refused when too few of them remain besides the launcher
- Team-based resource isolation: projects and all child resources (templates, inventories, jobs, schedules, approval requests, analytics) restricted to team members, workflows to members who may access the project of every job step; viewer role grants read-only access, operator role grants full CRUD + launch; only admins and superadmins manage teams, and a project that no team has is open to every signed-in user
- Schedules, triggers and workflow steps launch as a recorded user (for a schedule, the user who last changed what or when it runs, or enabled it; the user who generated the trigger token; the workflow's launcher), who never approves these jobs, and are refused once that user is disabled or may no longer launch the template
- Superadmin flag (`is_superadmin`) bypasses all team-scoped restrictions; only a superadmin sets or clears it, and a role is assigned only by a superadmin or a user who holds every permission the role grants, the `admin` role, and a role that contains it, only by a superadmin or an admin; a user who is no superadmin changes or deletes only users who are no superadmins, whose roles they may assign and, unless they are admins, whose teams give them no project beyond their own, nor a higher role on one, and gives a role only permissions they hold and changes or deletes only roles they may assign
- Signed-out visitors reach only the login, password-reset and error pages, the trigger endpoint and the health and metrics endpoints; the default `viewer` role applies to signed-in users only. In development mode (`YII_ENV=dev`) the debug and gii modules answer everyone who reaches the stack, so a dev stack must never be reachable by others
- All state-changing actions require explicit authorization
- Optional TOTP 2FA per user — disabling requires a current authenticator code or recovery code
- Recovery codes are bcrypt-hashed; rate limiting (5 attempts, 5 min lockout) on TOTP verification
- Ansible execution runs in isolated worker processes with auditable command construction
- Runner, API and trigger tokens are stored as SHA-256 hashes — raw tokens are shown exactly once
- Trigger tokens travel in a request header; tokens in URLs, still accepted but deprecated, are masked in the nginx access log
- The console log no longer records every SQL statement with its values, and log lines carry no session ID; the application log names database errors, also those that nothing catches and those that the framework logs itself, by their class, SQLSTATE and driver error code, never with their statement; the stack traces of errors leave out the arguments of calls (`#[\SensitiveParameter]`, and `zend.exception_ignore_args = On` in the images); `bin/diagnose` redacts secrets before printing
- Artifact collection skips symlinks to prevent path traversal and file exfiltration
- Fully offline capable — no CDN dependencies, no external asset loading; safe to deploy in air-gapped, classified, or compliance-restricted environments

---

## Docs

| | |
|---|---|
| [Installation](docs/installation.md) | Quickstart, manual setup, external DB, configuration reference |
| [Updating](docs/updating.md) | Before you update, quickstart and manual updates, verifying the update, what changes after updating from an older release, rollback, automated updates |
| [Runners](docs/runners.md) | Runner setup, groups, selftest |
| [Credentials](docs/credentials.md) | Credential types, attaching credentials to a job template, token env vars, vault passwords and vault files, security model, password survey answers, secrets in inventories and extra vars |
| [Workflows](docs/workflows.md) | Building workflows, positions and branches, editing steps, waiting runs and approvals, API |
| [Artifacts](docs/artifacts.md) | Producing artifacts in playbooks, where they appear, retention |
| [LDAP / Active Directory](docs/ldap.md) | Optional directory login: the container images that support it, how it works, configuration for Active Directory and OpenLDAP, console commands, REST API, account lifecycle, security model |
| [Deployment](docs/deployment.md) | Production Ansible role |
| [Monitoring](docs/monitoring.md) | Prometheus metrics, health endpoint |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and the diagnostics script |
| [Releasing](docs/releasing.md) | Release process |
| [Continuous integration](docs/ci.md) | What GitHub Actions and Scrutinizer check, and why Scrutinizer runs no tests |

---

## License

[Apache 2.0](LICENSE)

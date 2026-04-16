<p align="center">
  <img src="ansilume.svg" alt="Ansilume" width="360">
</p>

<p align="center">
  <strong>Self-hosted Ansible automation platform</strong><br>
  Run playbooks, manage infrastructure, track every execution — all from a clean UI.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white" alt="PHP 8.2">
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

**Requires:** Docker + Docker Compose

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
- **Async, isolated execution** — jobs run in queue workers and runner agents, never in the web request thread
- **Live job output** — streaming stdout/stderr with ANSI colors, per-task progress, and full play recap per host
- **Job artifacts & host summaries** — structured results captured via a custom Ansible callback plugin
- **Job templates** — reusable launch configs with extra vars, verbosity, become, forks, limits, tags, and survey fields for launch-time input
- **Workflows** — chain multiple templates with on-success / on-failure / always branches and manual approval gates
- **Pull-based runners** — lightweight agents self-register via bootstrap token; isolate execution from the control plane
- **Runner groups** — logical grouping with per-group tokens, health tracking, and a selftest playbook
- **Cancel & timeout** — in-flight jobs can be canceled; configurable per-template execution timeouts

### Inventory, projects & credentials
- **Git-backed projects** — sync playbooks from remote repositories, or manage content manually
- **Automatic ansible-lint** — lint results recorded on every project sync
- **Inventories** — static, file-based, and dynamic inventories parsed through real `ansible-inventory`
- **Credentials** — SSH key, username/password, vault secret, and token types, all AES-256-CBC encrypted at rest
- **SSH key tools** — in-UI ed25519 key generation with algorithm/strength analysis for uploaded keys

### Automation & integrations
- **Scheduled jobs** — cron-based scheduling with next/last-run tracking and catch-up protection
- **Inbound webhooks** — trigger templates from external systems with per-webhook tokens
- **Notifications v2** — email, Slack, Webhook, Telegram, and PagerDuty channels with a shared event catalog
- **REST API (v1)** — every feature exposed as an API; the UI is just one client
- **OpenAPI 3.1 spec** — canonical `web/openapi.yaml` + bundled Swagger UI at `:8088` for interactive exploration
- **Analytics dashboard** — job, workflow, approval, and runner statistics with time-window filters

### Access control & auditability
- **Custom role management** — built-in `viewer` / `operator` / `admin` plus user-defined roles with domain-grouped permission editor
- **Multi-tenant team scoping** — assign projects to teams with viewer or operator roles; all child resources (templates, inventories, jobs, schedules) inherit access from their parent project. Users only see resources belonging to their teams. Admins bypass all restrictions. Unscoped projects remain visible to everyone for backward compatibility
- **Two-factor authentication** — optional TOTP 2FA per user (Google Authenticator, Authy, 1Password, …) with bcrypt-hashed recovery codes and rate-limited verification
- **Password reset** — self-service reset via signed email link
- **Full audit trail** — every launch, configuration change, credential access, and permission edit recorded against an actor
- **Approval workflows** — gate sensitive steps behind named approval rules with per-rule approver lists

### Operations
- **Air-gapped / offline ready** — all CSS, JavaScript, and fonts are bundled locally; the UI requires no outbound internet access and works fully offline in isolated or restricted networks
- **Monitoring endpoints** — [Prometheus and JSON metrics](docs/monitoring.md) for jobs, tasks, hosts, runners, queues, and infrastructure health
- **Health dashboard** — at-a-glance status of database, Redis, queue, runners, and migrations
- **Production deployment** — [Ansible role](docs/deployment.md) for automated install, upgrades, and rollbacks
- **Docker-native** — reproducible dev and prod stacks via `docker compose`, prebuilt images on ghcr.io

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
Browser → Nginx → PHP-FPM (Yii2) → MariaDB
                                  → Redis (queue / cache / sessions)
                       ↓
                  Queue Worker
                       ↓
                  Runner Agent(s) → ansible-playbook
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
- TOTP secrets are AES-256-CBC encrypted at rest — the same key used for credentials
- RBAC roles: `viewer` (read-only) · `operator` (launch + manage) · `admin` (full access) — plus custom roles
- Team-based resource isolation: projects and all child resources (templates, inventories, jobs, schedules) restricted to team members; viewer role grants read-only access, operator role grants full CRUD + launch
- Superadmin flag (`is_superadmin`) bypasses all team-scoped restrictions
- All state-changing actions require explicit authorization
- Optional TOTP 2FA per user — disabling requires a current authenticator code or recovery code
- Recovery codes are bcrypt-hashed; rate limiting (5 attempts, 5 min lockout) on TOTP verification
- Ansible execution runs in isolated worker processes with auditable command construction
- Runner tokens are stored as SHA-256 hashes — raw tokens are shown exactly once
- Artifact collection skips symlinks to prevent path traversal and file exfiltration
- Fully offline capable — no CDN dependencies, no external asset loading; safe to deploy in air-gapped, classified, or compliance-restricted environments

---

## Docs

| | |
|---|---|
| [Installation](docs/installation.md) | Quickstart, manual setup, external DB, configuration reference |
| [Runners](docs/runners.md) | Runner setup, groups, selftest |
| [Artifacts](docs/artifacts.md) | Producing artifacts in playbooks, where they appear, retention |
| [Deployment](docs/deployment.md) | Production Ansible role |
| [Monitoring](docs/monitoring.md) | Prometheus metrics, health endpoint |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and the diagnostics script |
| [Releasing](docs/releasing.md) | Release process |

---

## License

[Apache 2.0](LICENSE)

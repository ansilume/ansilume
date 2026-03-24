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
  <img src="https://img.shields.io/badge/License-Apache%202.0-green" alt="License">
</p>

---

<p align="center">
  <img src="docs/screenshots/dashboard.png" width="800" alt="Dashboard">
</p>

---

## Overview

Ansilume is a **production-ready, self-hostable automation control plane** built for infrastructure teams. It gives you a reliable web interface to manage and execute Ansible workloads — with full auditability, role-based access control, and zero execution in the web request lifecycle.

Think of it as a lightweight, self-hosted alternative to AWX or Semaphore — designed for teams that want **operational clarity** over feature bloat.

---

## Features

- **Async execution** — jobs run in isolated worker processes, never in web requests
- **Full audit trail** — every launch, change, and access event is recorded
- **Role-based access** — `viewer`, `operator`, `admin` roles with team-scoped project access
- **Secure credential storage** — AES-256-CBC encrypted at rest, never exposed in logs or HTML
- **Pull-based runners** — lightweight runner agents poll for work and self-register via bootstrap token
- **Scheduled jobs** — cron-based scheduling with next/last run tracking
- **Inbound webhooks** — trigger template executions from external systems
- **Live job output** — streaming stdout/stderr with per-task play recap
- **Git-backed projects** — sync playbooks from remote repositories
- **Lint integration** — automatic ansible-lint on project sync

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

## Quick Start

**Prerequisites:** Docker + Docker Compose

```bash
git clone https://github.com/ansilume/ansilume.git
cd ansilume

cp .env.example .env

# Generate random secrets automatically
sed -i \
  -e "s|^COOKIE_VALIDATION_KEY=.*|COOKIE_VALIDATION_KEY=$(openssl rand -hex 32)|" \
  -e "s|^APP_SECRET_KEY=.*|APP_SECRET_KEY=$(openssl rand -hex 32)|" \
  -e "s|^RUNNER_BOOTSTRAP_SECRET=.*|RUNNER_BOOTSTRAP_SECRET=$(openssl rand -hex 24)|" \
  .env
```

Then start the stack:

```bash
# Linux: export host UID if it differs from 1000
# export UID GID

docker compose up -d --build

# Create the first admin user
docker compose exec app php yii setup/admin admin admin@example.com yourpassword
```

Open **http://localhost:8080** and log in.

> Migrations run automatically on startup. No manual steps required.

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

### Job lifecycle

```
pending → queued → running → succeeded
                           → failed
                           → canceled
```

Jobs are created by web requests, executed by runner agents, and never block the HTTP layer.

### Runners

Runners are pull-based agents that poll the server for queued jobs. They self-register on first start using `RUNNER_BOOTSTRAP_SECRET` — no manual token management required in development.

```bash
# Runner containers register automatically via:
RUNNER_NAME=runner-1
RUNNER_BOOTSTRAP_SECRET=your-secret
API_URL=http://nginx
```

---

## Configuration

All configuration is via `.env`. Key variables:

| Variable | Description |
|---|---|
| `COOKIE_VALIDATION_KEY` | Yii2 cookie signing key (min 32 chars) |
| `APP_SECRET_KEY` | AES-256 key for credential encryption (min 32 chars) |
| `RUNNER_BOOTSTRAP_SECRET` | Shared secret for runner self-registration |
| `DB_*` | MariaDB connection settings |
| `REDIS_*` | Redis connection settings |
| `SMTP_*` | Mail settings for failure notifications |

---

## Development

```bash
docker compose up -d                # Start all services
docker compose logs -f app          # PHP-FPM logs
docker compose logs -f queue-worker # Queue worker logs

# Migrations (run automatically on startup, manual trigger if needed)
docker compose exec app php yii migrate

# Console help
docker compose exec app php yii help
```

### Health checks

All containers expose meaningful health checks. Verify status with:

```bash
docker compose ps
```

All services should show `(healthy)` after startup.

### Rebuilding containers

After changes to `docker/` (Dockerfile, PHP config, packages):

```bash
export UID GID   # Linux only, if UID != 1000
docker compose down
docker compose build --no-cache
docker compose up -d
```

### Linux: UID alignment

The PHP containers match `www-data` to your host UID. If your UID is not `1000`:

```bash
export UID GID
docker compose up -d --build
```

Add `export UID GID` to your shell profile to apply automatically. Not needed when running as root.

---

## Security

- Credentials are AES-256-CBC encrypted at rest — raw secrets never appear in logs or HTML
- RBAC roles: `viewer` (read-only) · `operator` (launch + manage) · `admin` (full access)
- Superadmin flag (`is_superadmin`) bypasses team-scoped restrictions
- All state-changing actions require explicit authorization
- Ansible execution runs in isolated worker processes with auditable command construction
- Runner tokens are stored as SHA-256 hashes — raw tokens are shown exactly once

---

## License

[Apache 2.0](LICENSE)

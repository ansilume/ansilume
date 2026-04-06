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
- **Team-scoped access** — projects, inventories, credentials, and templates scoped per team
- **Two-factor authentication** — optional TOTP 2FA per user (Google Authenticator, Authy, 1Password, …) with bcrypt-hashed recovery codes and rate-limited verification
- **Password reset** — self-service reset via signed email link
- **Full audit trail** — every launch, configuration change, credential access, and permission edit recorded against an actor
- **Approval workflows** — gate sensitive steps behind named approval rules with per-rule approver lists

### Operations
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

## Quick Start

**Prerequisites:** Docker + Docker Compose

### Prebuilt images (recommended for homelab / production)

No git required — pull directly from the GitHub Container Registry.

```bash
# 1. Download the compose file and a production .env template
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/docker-compose.prebuilt.yml -o docker-compose.yml
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/.env.prod.example -o .env

# 2. Generate secrets and set passwords
sed -i \
  -e "s|COOKIE_VALIDATION_KEY=CHANGE-ME|COOKIE_VALIDATION_KEY=$(openssl rand -hex 32)|" \
  -e "s|APP_SECRET_KEY=CHANGE-ME|APP_SECRET_KEY=$(openssl rand -hex 32)|" \
  -e "s|RUNNER_BOOTSTRAP_SECRET=CHANGE-ME|RUNNER_BOOTSTRAP_SECRET=$(openssl rand -hex 24)|" \
  -e "s|DB_ROOT_PASSWORD=CHANGE-ME|DB_ROOT_PASSWORD=$(openssl rand -hex 16)|" \
  -e "s|DB_PASSWORD=CHANGE-ME|DB_PASSWORD=$(openssl rand -hex 16)|" \
  .env

# 3. Start
docker compose up -d

# 4. Create the first admin user
docker compose exec app php yii setup/admin admin admin@example.com yourpassword
```

Open **http://localhost:8080** and log in.

> Migrations run automatically on startup. No manual steps required.

### Build from source (development / CI)

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

# Linux: export host UID if it differs from 1000
# export UID GID

docker compose up -d --build

# Create the first admin user
docker compose exec app php yii setup/admin admin admin@example.com yourpassword
```

Open **http://localhost:8080** and log in.

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
- TOTP secrets are AES-256-CBC encrypted at rest — the same key used for credentials
- RBAC roles: `viewer` (read-only) · `operator` (launch + manage) · `admin` (full access)
- Superadmin flag (`is_superadmin`) bypasses team-scoped restrictions
- All state-changing actions require explicit authorization
- Optional TOTP 2FA per user — disabling requires a current authenticator code or recovery code
- Recovery codes are bcrypt-hashed; rate limiting (5 attempts, 5 min lockout) on TOTP verification
- Ansible execution runs in isolated worker processes with auditable command construction
- Runner tokens are stored as SHA-256 hashes — raw tokens are shown exactly once
- Artifact collection skips symlinks to prevent path traversal and file exfiltration

---

## Production Deployment

Ansilume includes an Ansible role for automated production deployment. See [docs/deployment.md](docs/deployment.md) for the full guide.

```bash
cd deploy
ansible-playbook site.yaml -i inventory/production.yaml --ask-vault-pass
```

The role handles Docker installation, configuration templating, container orchestration, and health verification. Supports external databases and custom runner counts.

---

## Troubleshooting

See [docs/troubleshooting.md](docs/troubleshooting.md) for common issues — container race conditions, runner registration, health checks, and more.

---

## Releasing

```bash
./bin/release patch   # 0.1.0 → 0.1.1
./bin/release minor   # 0.1.0 → 0.2.0
./bin/release major   # 0.1.0 → 1.0.0
```

See [docs/releasing.md](docs/releasing.md) for the full release process.

---

## License

[Apache 2.0](LICENSE)

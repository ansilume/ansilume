# Installation

## Quickstart (recommended)

The interactive installer handles everything — prerequisites check, configuration, secrets, database setup, migrations, and admin user creation.

```bash
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/quickstart | bash
```

You will be asked:
- **Deployment mode** — prebuilt images (default) or build from git source
- **Install directory** — where to create the configuration files (default: current working directory `.`; point it at an empty subdirectory if you want a dedicated tree)
- **Database** — new managed MariaDB container (default) or connect to an existing server
- **HTTP port** — default `8080`
- **Admin account** — username, email, password

All secrets (`COOKIE_VALIDATION_KEY`, `APP_SECRET_KEY`, `RUNNER_BOOTSTRAP_SECRET`, database passwords) are generated automatically.

---

## Manual setup — prebuilt images

No git required. Pulls directly from the GitHub Container Registry.

```bash
# 1. Create a working directory
mkdir ansilume && cd ansilume

# 2. Download the compose file and .env template
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/docker-compose.prebuilt.yml -o docker-compose.yml
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/.env.prod.example         -o .env

# 3. Generate secrets
sed -i \
  -e "s|COOKIE_VALIDATION_KEY=CHANGE-ME|COOKIE_VALIDATION_KEY=$(openssl rand -hex 32)|" \
  -e "s|APP_SECRET_KEY=CHANGE-ME|APP_SECRET_KEY=$(openssl rand -hex 32)|" \
  -e "s|RUNNER_BOOTSTRAP_SECRET=CHANGE-ME|RUNNER_BOOTSTRAP_SECRET=$(openssl rand -hex 24)|" \
  -e "s|DB_ROOT_PASSWORD=CHANGE-ME|DB_ROOT_PASSWORD=$(openssl rand -hex 16)|" \
  -e "s|DB_PASSWORD=CHANGE-ME|DB_PASSWORD=$(openssl rand -hex 16)|" \
  .env

# 4. Start
docker compose up -d

# 5. Create the first admin user
docker compose exec app php yii setup/admin admin admin@example.com yourpassword
```

Open **http://localhost:8080**.

> Migrations run automatically on startup. The `setup/admin` command also seeds the demo project and selftest template.

---

## Manual setup — build from source

```bash
git clone https://github.com/ansilume/ansilume.git
cd ansilume

cp .env.example .env

sed -i \
  -e "s|^COOKIE_VALIDATION_KEY=.*|COOKIE_VALIDATION_KEY=$(openssl rand -hex 32)|" \
  -e "s|^APP_SECRET_KEY=.*|APP_SECRET_KEY=$(openssl rand -hex 32)|" \
  -e "s|^RUNNER_BOOTSTRAP_SECRET=.*|RUNNER_BOOTSTRAP_SECRET=$(openssl rand -hex 24)|" \
  .env

# Linux: export host UID if it differs from 1000
# export UID GID

docker compose up -d --build

docker compose exec app php yii setup/admin admin admin@example.com yourpassword
```

---

## External database

To connect to an existing MySQL or MariaDB server, set the following in `.env` and remove the `db:` service from `docker-compose.yml`:

```dotenv
DB_HOST=your-db-host
DB_PORT=3306
DB_NAME=ansilume
DB_USER=ansilume
DB_PASSWORD=your-password
```

The database and user must exist before starting the stack. Ansilume only needs standard DML/DDL permissions — no `SUPER` or `GRANT` required.

---

## Configuration reference

All configuration is via `.env`. Variables with no default are required; everything else falls back to the value shown.

### Core

| Variable | Description |
|---|---|
| `APP_ENV` / `YII_ENV` | Environment selector — `prod` or `dev` (default: `prod` in `.env.prod.example`) |
| `YII_DEBUG` | Set to `1` to enable the Yii debug toolbar and verbose error pages; leave at `0` in production |
| `APP_URL` | Externally reachable base URL (e.g. `https://ansilume.example.com` or `http://10.1.42.102:9911`). Drives absolute links in job notifications, password-reset mails, webhook payloads, and the OpenAPI spec. Without this, background-triggered notifications fall back to the internal docker hostname (`http://nginx/…`). |
| `NGINX_PORT` | Host port for the web UI (default: `8080`) |
| `COOKIE_VALIDATION_KEY` | Yii2 cookie signing key — at least 32 characters |
| `APP_SECRET_KEY` | AES-256 key for credential encryption — at least 32 characters |
| `SESSION_COOKIE_SECURE` | Only send the session cookie over HTTPS (default: `true` in prod). Set to `false` if you deliberately terminate TLS outside Ansilume and cannot forward `X-Forwarded-Proto`. |
| `SESSION_TIMEOUT` | Session lifetime in seconds (default: `43200` = 12h) |

### Database

| Variable | Description |
|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | Database connection |
| `DB_ROOT_PASSWORD` | MariaDB root password — only needed for the managed container |
| `COMPOSE_PROFILES` | Set to `internal-db` (default) to start the bundled MariaDB container; leave empty when using an external database |

### Redis

| Variable | Description |
|---|---|
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_DB` | Redis connection (used for queue + cache) |

### Runners

| Variable | Description |
|---|---|
| `RUNNER_BOOTSTRAP_SECRET` | Shared secret for runner self-registration |
| `RUNNER_MODE` | `local` (default, uses the bundled runner containers) or `remote` (register external runners) |

### Jobs

| Variable | Description |
|---|---|
| `JOB_PROGRESS_TIMEOUT` | Seconds without log progress before a running job is considered stuck (default: `600`) |
| `JOB_RECLAIM_MODE` | What to do when a stuck job is reclaimed: `fail` (default) or `requeue` |
| `JOB_QUEUE_TIMEOUT` | Max seconds a job may stay queued before being failed (default: `1800`) |

### Email / notifications

| Variable | Description |
|---|---|
| `SMTP_HOST` / `SMTP_PORT` | SMTP server (default: port `587`). Leave `SMTP_HOST` empty to disable email. |
| `SMTP_ENCRYPTION` | `tls` (default), `ssl`, or empty for plaintext |
| `SMTP_USER` / `SMTP_PASSWORD` | SMTP credentials (optional) |
| `SENDER_EMAIL` | `From:` address on outgoing mail (default: `noreply@example.com`) |
| `ADMIN_EMAIL` | Destination for system-level alerts like runner-offline |

### Artifacts

| Variable | Description |
|---|---|
| `ARTIFACT_MAX_FILE_SIZE` | Max single artifact file size in bytes (default: `10485760` / 10 MB) |
| `ARTIFACT_MAX_BYTES_PER_JOB` | Max total artifact bytes per job (default: `52428800` / 50 MB) |
| `ARTIFACT_MAX_TOTAL_BYTES` | Global cap across all jobs, `0` = unlimited (default: `0`) |
| `ARTIFACT_RETENTION_DAYS` | Days to keep artifacts, `0` = forever (default: `0`) |
| `ARTIFACT_MAX_JOBS_WITH_ARTIFACTS` | Keep at most N most-recent jobs' artifacts per template, `0` = unlimited (default: `0`) |
| `MAINTENANCE_ARTIFACT_CLEANUP_INTERVAL` | Seconds between scheduled cleanup runs (default: `86400` = 24h) |

### LDAP / AD (optional)

| Variable | Description |
|---|---|
| `LDAP_ENABLED` | `false` by default. Set to `true` to enable — see [ldap.md](ldap.md) for the full variable set. |

### Audit

| Variable | Description |
|---|---|
| `AUDIT_SYSLOG_ENABLED` | Mirror audit events to syslog (default: `false`) |
| `AUDIT_SYSLOG_IDENT` | Syslog ident (default: `ansilume`) |
| `AUDIT_SYSLOG_FACILITY` | Syslog facility constant (default: `LOG_LOCAL0`) |

---

## Development setup

```bash
git clone https://github.com/ansilume/ansilume.git
cd ansilume

cp .env.example .env

docker compose up -d

# Follow logs
docker compose logs -f app queue-worker

# Run migrations manually if needed
docker compose exec app php yii migrate

# Create admin user
docker compose exec app php yii setup/admin admin admin@example.com yourpassword

# Full test suite
./tests.sh
```

### Linux UID alignment

The PHP containers match `www-data` to your host UID. If your UID is not `1000`:

```bash
export UID GID
docker compose up -d --build
```

Add `export UID GID` to your shell profile to make it permanent.

---

## Updating

See [updating.md](updating.md) for how to update an existing installation — interactive, unattended, manual, and rollback procedures.

---

## Production deployment via Ansible

Ansilume includes an Ansible role for automated production deployment, upgrades, and rollbacks. See [deployment.md](deployment.md) for the full guide.

```bash
cd deploy
ansible-playbook site.yaml -i inventory/production.yaml --ask-vault-pass
```

---

## Troubleshooting

See [troubleshooting.md](troubleshooting.md) for common issues — container race conditions, runner registration, health checks, and more.

Run the diagnostics script at any time:

```bash
./bin/diagnose
# or remotely:
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/diagnose | bash
```

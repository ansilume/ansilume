# Installation

## Quickstart (recommended)

The interactive installer handles everything — prerequisites check, configuration, secrets, database setup, migrations, and admin user creation.

```bash
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/quickstart | bash
```

You will be asked:
- **Deployment mode** — prebuilt images (default) or build from git source
- **Install directory** — where to create the configuration files (default: `./ansilume`)
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

All configuration is via `.env`. Key variables:

| Variable | Description |
|---|---|
| `COOKIE_VALIDATION_KEY` | Yii2 cookie signing key — must be at least 32 characters |
| `APP_SECRET_KEY` | AES-256 key for credential encryption — must be at least 32 characters |
| `RUNNER_BOOTSTRAP_SECRET` | Shared secret for runner self-registration |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | Database connection |
| `DB_ROOT_PASSWORD` | MariaDB root password — only needed for the managed container |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_DB` | Redis connection |
| `NGINX_PORT` | Host port for the web UI (default: `8080`) |
| `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASSWORD` / `SMTP_FROM` | Optional email for notifications |

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

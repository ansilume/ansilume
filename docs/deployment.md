# Production Deployment

Ansilume ships with an Ansible role in `deploy/` that automates production installation.

## Prerequisites

- Target server: Debian 12+, Ubuntu 22.04+, AlmaLinux 8/9, or RHEL 8/9
- **Docker and Docker Compose plugin** must be installed on the target server (the role verifies this but does not install Docker)
- Ansible 2.14+ on the control machine
- `community.docker` collection (`ansible-galaxy collection install community.docker`)
- SSH access to the target server with `become` (sudo) privileges

## Quick start

```bash
cd deploy

# 1. Configure your inventory
cp inventory/production.yaml inventory/myenv.yaml
# Edit inventory/myenv.yaml — add your server(s)

# 2. Set secrets (use ansible-vault for production!)
ansible-vault create group_vars/vault.yaml
# Add:
#   vault_cookie_validation_key: <openssl rand -hex 32>
#   vault_app_secret_key: <openssl rand -hex 32>
#   vault_db_password: <openssl rand -hex 16>
#   vault_db_root_password: <openssl rand -hex 16>
#   vault_runner_bootstrap_secret: <openssl rand -hex 24>

# 3. Reference vault in group_vars
cat >> group_vars/ansilume.yaml <<EOF
ansilume_cookie_validation_key: "{{ vault_cookie_validation_key }}"
ansilume_app_secret_key: "{{ vault_app_secret_key }}"
ansilume_db_password: "{{ vault_db_password }}"
ansilume_db_root_password: "{{ vault_db_root_password }}"
ansilume_runner_bootstrap_secret: "{{ vault_runner_bootstrap_secret }}"
EOF

# 4. Deploy
ansible-playbook site.yaml -i inventory/myenv.yaml --ask-vault-pass
```

## What the role does

1. **Validates** that production secrets are set (fails fast if defaults remain)
2. **Verifies** Docker and Docker Compose are installed and running
3. **Creates** a system user and deployment directory (`/opt/ansilume`)
4. **Clones** the repository (or extracts a release archive)
5. **Templates** all configuration files (`.env`, `docker-compose.yaml`, nginx, PHP, MariaDB)
6. **Starts** the full container stack via Docker Compose
7. **Waits** for the health endpoint to return HTTP 200

The role is fully idempotent — running it again only applies changes.

## Architecture

The production stack consists of:

| Container | Purpose |
|-----------|---------|
| `app` | PHP-FPM application server |
| `nginx` | Web server / reverse proxy |
| `db` | MariaDB database (optional — see external DB) |
| `redis` | Cache, sessions, job queue |
| `queue-worker` | Processes async jobs from Redis |
| `schedule-runner` | Executes scheduled jobs every minute |
| `runner-1..N` | Pull-based Ansible execution agents |

## Using an external database

To connect to an existing MariaDB/MySQL server instead of running a local container:

```yaml
# group_vars/ansilume.yaml
ansilume_db_local: false
ansilume_db_host: db.internal.example.com
ansilume_db_port: 3306
ansilume_db_name: ansilume
ansilume_db_user: ansilume
ansilume_db_password: "{{ vault_db_password }}"
```

When `ansilume_db_local: false`:
- No MariaDB container is deployed
- The app connects directly to the specified host
- You are responsible for creating the database and user beforehand

## Using an external Redis

Similarly, to use an existing Redis instance:

```yaml
ansilume_redis_local: false
ansilume_redis_host: redis.internal.example.com
ansilume_redis_port: 6379
ansilume_redis_db: 0
```

## Configuration reference

All variables are documented in `deploy/roles/ansilume/defaults/main.yaml`. Key categories:

### Paths and source

| Variable | Default | Description |
|----------|---------|-------------|
| `ansilume_install_dir` | `/opt/ansilume` | Installation directory on the target |
| `ansilume_repo_url` | GitHub URL | Git repository to clone |
| `ansilume_version` | `main` | Branch or tag to deploy |
| `ansilume_release_archive` | `""` | Local tarball path (skips git clone) |

### Secrets (must override)

| Variable | Description |
|----------|-------------|
| `ansilume_cookie_validation_key` | Yii2 cookie signing key (min 32 chars) |
| `ansilume_app_secret_key` | AES-256-CBC key for credential encryption |
| `ansilume_db_password` | Database user password |
| `ansilume_db_root_password` | MariaDB root password (local DB only) |
| `ansilume_runner_bootstrap_secret` | Runner self-registration shared secret |

### Infrastructure

| Variable | Default | Description |
|----------|---------|-------------|
| `ansilume_db_local` | `true` | Deploy local MariaDB container |
| `ansilume_redis_local` | `true` | Deploy local Redis container |
| `ansilume_nginx_port` | `8080` | Port nginx listens on |
| `ansilume_runner_count` | `2` | Number of runner containers |

### Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `ansilume_smtp_host` | `""` | SMTP server (empty = no mail) |
| `ansilume_smtp_port` | `587` | SMTP port |
| `ansilume_smtp_encryption` | `tls` | `tls`, `ssl`, or empty |
| `ansilume_smtp_user` | `""` | SMTP username |
| `ansilume_smtp_password` | `""` | SMTP password |
| `ansilume_admin_email` | `admin@example.com` | Admin notification address |
| `ansilume_sender_email` | `noreply@ansilume.local` | From address |

## Scaling runners

To increase the number of runner containers:

```yaml
ansilume_runner_count: 4
```

Each runner auto-registers using the bootstrap secret and polls for jobs independently.

## HTTPS / TLS

The nginx container listens on HTTP only. For HTTPS, place a reverse proxy in front:

- **Traefik**: Add labels to the nginx container
- **Caddy**: Reverse proxy to `localhost:8080`
- **nginx on host**: Proxy pass with SSL termination
- **Cloud LB**: Terminate TLS at the load balancer

## Backup

Critical data to back up:

1. **Database** — `mysqldump` or volume snapshot of `db_data`
2. **`.env` file** — contains encryption keys; losing `APP_SECRET_KEY` makes stored credentials unreadable
3. **`runtime/projects/`** — cloned git repositories (can be re-synced, but saves time)

## Updating

```bash
cd deploy
ansible-playbook site.yaml -i inventory/myenv.yaml --ask-vault-pass -e ansilume_version=v1.2.0
```

The role pulls the new version, rebuilds containers, and runs migrations automatically.

## Troubleshooting

See [troubleshooting.md](troubleshooting.md) for common issues and the diagnostics script.

# Updating Ansilume

This guide covers how to update an existing Ansilume installation to the latest version.

## Before you update

1. **Back up your `.env` file** — it contains your secrets and configuration.
2. **Back up your database** — in case a migration needs to be investigated.

```bash
cp .env .env.backup
docker compose exec db mysqldump -uansilume -p"$DB_PASSWORD" ansilume > backup.sql
```

Database migrations run automatically when the app container starts. You do not need to run them manually.

---

## Quickstart update (recommended)

The quickstart script supports updating existing installations. It detects whether you installed via prebuilt images or from source and handles both modes automatically.

### Interactive

Re-run the quickstart from your install directory. It will detect the existing `.env` and offer an update option:

```bash
cd /path/to/ansilume
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/quickstart | bash
```

Choose **[2] Update** when prompted. The script will:

- Pull the latest source (git) or re-download the compose file (prebuilt)
- Merge any new configuration variables into your `.env` (existing values are never overwritten)
- Pull the latest container images
- Restart all services
- Wait for the app to become healthy

### Unattended

Use `--update` for fully non-interactive updates (suitable for cron or scripts):

```bash
cd /path/to/ansilume
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/quickstart | bash -s -- --update
```

Add `-v` for debug output or `-vv` for full Docker output:

```bash
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/quickstart | bash -s -- --update -v
```

If no existing installation is found (no `.env` in the current directory), the script exits with an error.

---

## Manual update — prebuilt images

```bash
cd /path/to/ansilume

# 1. Re-download the latest compose file
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/docker-compose.prebuilt.yml -o docker-compose.yml

# 2. Pull latest images
docker compose pull

# 3. Restart
docker compose up -d
```

Migrations run automatically on container startup.

---

## Manual update — source (git)

```bash
cd /path/to/ansilume

# 1. Pull latest changes
git pull --ff-only

# 2. Pull latest base images and rebuild
docker compose up -d --build
```

If `git pull` fails with merge conflicts, you have local modifications. Either stash them (`git stash`) or reset to upstream (`git reset --hard origin/main`).

---

## What happens during an update

1. **Compose file** — prebuilt installations get the latest `docker-compose.yml` from GitHub. Source installations get it via `git pull`.
2. **Container images** — all services pull the latest images from `ghcr.io/ansilume/`.
3. **Database migrations** — the app container's entrypoint runs `php yii migrate --interactive=0` on every start. This is idempotent and safe to run repeatedly.
4. **Configuration** — the quickstart merges new `.env` variables that were introduced in newer versions. Your existing values are never modified.
5. **Health check** — the quickstart waits for the app container to report healthy.
6. **HTTP verification** — the quickstart then probes the stack from the host through the published port: the `/health` endpoint, the `/` → `/login` redirect, the login page, and one published asset (catches a missing `web_assets` volume). If any check fails, the script prints what failed, points you at the diagnostics script, and **exits with status 1**. Unattended runs (cron, CI) can rely on that exit code.

---

## Verifying the update

After updating, verify the application is running correctly:

```bash
# Check container status
docker compose ps

# Check app health
docker compose exec app php yii health/check

# Check the health endpoint (adjust the port to your NGINX_PORT)
curl -s http://localhost:8080/health

# Follow the login redirect — a bare `curl` on the base URL prints nothing,
# because the 302 to /login has an empty body. Use -IL to see the headers.
curl -IL http://localhost:8080/
```

Run the diagnostics script if anything looks wrong:

```bash
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/diagnose | bash
```

---

## After updating from 2.5.0 or older: API permissions, runner tokens

**The REST API enforces the same permissions as the web UI.** Until now
several endpoints checked no permission at all: workflow templates and
workflow jobs, notification templates, approval rules and approvals,
analytics, and the read endpoints of credentials, schedules, runners, runner
groups, projects, inventories, job templates and jobs. Every endpoint now
requires the permission of the matching web page and answers `403` without
it. What existing tokens notice:

- Viewer tokens can no longer list schedules (that needs `job.launch`, as in
  the web UI) or export analytics as CSV (`analytics.export`).
- Tokens of users with a custom role need that role's view permissions for
  reads, for example `project.view`.
- Tokens of disabled users are rejected with `401`. Before, their requests
  continued as a guest.

The default roles keep everything they can do in the web UI.

**Confirm dialogs no longer run names as code.** A runner name with a double
quote could inject HTML attributes into the runner group page (stored
cross-site scripting), and an apostrophe in a token, role or team name could
break out of a confirm dialog's JavaScript. Runner names come from
self-registration, so anyone with the bootstrap secret could plant one. If a
runner group lists runners with unusual names, delete them and rotate
`RUNNER_BOOTSTRAP_SECRET`.

**Runner token resets are audited and shown.** When a runner registers again
under an existing name, the server issues a new token and revokes the old
one. This is now logged as `runner.reregistered` and shown on the runner group
page and in the Runners API. The bundled runners of the prebuilt compose file
and the deploy role keep their token in a named volume, so recreating them no
longer re-registers them. Quickstart `--update` downloads the new compose
file; manual prebuilt updates must download it as well.

**The OpenAPI spec version follows the app version.** `info.version` in
`/openapi.yaml` is now the Ansilume version instead of a separate number.

## After updating from 2.4.6 or older: runner network, Redis password, maintenance

**Bundled runners get their own network.** Playbooks run on runners, and until
now the bundled runners shared one Docker network with every other service.
A playbook could reach php-fpm on `app:9000` (FastCGI has no authentication,
so that meant running PHP code inside the app container), the database, and
Redis. Runners now sit on a separate `runners` network that only nginx joins;
they still reach the server through `API_URL=http://nginx` and keep normal
outbound access for playbooks and git.

- Quickstart `--update` downloads the new compose file automatically.
- Manual prebuilt updates must download the new `docker-compose.prebuilt.yml`
  before `docker compose up -d`, otherwise the runners stay on the shared
  network.
- If a playbook or a git remote addressed another container on the old
  network (for example a Gitea container), attach that container to the
  `runners` network or reach it through a host name instead.
- The Ansible deploy role now runs its runners from the standalone runner
  image in build mode too, without mounting the install directory. Until now
  they mounted it read-write, so playbooks could read `.env` and change the
  application code. The runners clone git projects themselves; a manual
  project whose path only exists on the server no longer runs on them. In
  prebuilt mode the role now shares the project and asset volumes like
  `docker-compose.prebuilt.yml`; before, nginx could not serve the published
  assets.
- In a git checkout, the dev-only ports (database, Redis, adminer, mailhog,
  swagger) now bind to `127.0.0.1`. Set `DEV_BIND_ADDRESS=0.0.0.0` in `.env`
  to reach them from another machine on a trusted network. The runners of a
  git checkout still mount the source tree, so playbooks there can read
  `.env` and change the code. Use that setup for development only.

**Redis can require a password.** Set `REDIS_PASSWORD` in `.env`; the bundled
Redis container then enforces it and every Ansilume connection authenticates.
Quickstart (`--update` and fresh installs) generates one for installations that
use the bundled Redis, after pulling the images and only once the pulled app
image supports it, so an older image never locks itself out. For an external
Redis it adds an empty value; set it to that server's password. For manual
updates, pull the new images first, then add a line like this to `.env` before
`docker compose up -d`:

```bash
echo "REDIS_PASSWORD=$(openssl rand -hex 32)" >> .env
```

Use a value without `$`, because docker compose interpolates it. In a git
checkout the containers read `.env` themselves, so after changing
`REDIS_PASSWORD` run `docker compose up -d --force-recreate` once; otherwise
the long-running queue-worker keeps connecting with the old setting. To roll back
to an older release, roll back the compose file together with the images, or
empty `REDIS_PASSWORD` first: older releases ignore it while the new Redis
service enforces it.

**Queue messages are allowlisted.** The queue-worker only accepts Ansilume's
own job messages. Anything else in the queue is logged and dropped instead of
being unserialized.

**Prebuilt installations run the maintenance tasks.** The schedule-runner of
the prebuilt compose file and of the Ansible deploy role now also runs
`php yii maintenance/run` every minute, as git checkouts always did. It
recovers project syncs stuck in `syncing` and applies the artifact retention
settings.

**`RUNNER_MODE` and `RUNNER_DOCKER_IMAGE` are gone.** They only applied to an
execution path inside the queue-worker that no longer ran any jobs. Leftover
values in `.env` are ignored.

## After updating from 2.4.5 or older: playbook environment and exposed secrets

**Playbooks no longer inherit the runner's environment.** They only see
`PATH`, locale, proxy and CA settings, `ANSIBLE_*` variables and the env vars
of attached Token credentials. If a playbook reads variables you set on the
runner (`lookup('env', ...)`, cloud SDK credentials), list their names in
`RUNNER_ENV_PASSTHROUGH` on the runner, or better, move secrets into Token
credentials. The runner logs at start which variables it does not forward.
See [runners.md](runners.md#what-playbooks-can-see).

Lint and inventory parsing on the server now see the same restricted set:
`PATH`, locale, proxy and CA settings and `ANSIBLE_*` variables. If a lint
setup relied on other variables, for example a vault password script that reads
an env var, point `ANSIBLE_VAULT_PASSWORD_FILE` at a mounted file instead.

Update **every runner image** as well. Old runner images keep passing their
full environment, including `RUNNER_BOOTSTRAP_SECRET`, to playbooks. Git
checkouts need `docker compose up -d --build` once, because the PHP images
gained the FFI extension.

**Check whether secrets were exposed.** Up to 2.4.5, `ansible-lint` ran with
the full server environment, and lint runs code from the project repository
(for example a vault password script referenced from the repo's
`ansible.cfg`). Lint runs automatically when a job template is saved and after
every project sync. If anyone you do not fully trust could change a repository
that Ansilume syncs, treat these as exposed and rotate them:

- `DB_PASSWORD` and `DB_ROOT_PASSWORD`
- `SMTP_PASSWORD` and `LDAP_BIND_PASSWORD`, if set
- `COOKIE_VALIDATION_KEY` (logs out all users)
- `RUNNER_BOOTSTRAP_SECRET` (update every runner afterwards)
- any other secret you put into `.env` or the server containers' environment
- `APP_SECRET_KEY`: changing it makes all stored credentials unreadable, and
  there is no re-encryption command yet, so every credential has to be entered
  again. Rotate it, and the secrets stored in your credentials, if the
  repositories were not under your control.

Playbook authors could also read `RUNNER_BOOTSTRAP_SECRET` on runners that have
it set, and on the official runner image they still can (see
[runners.md](runners.md#what-playbooks-can-see)). Rotating it does not help
against that; give such runners a `RUNNER_TOKEN` instead.

---

## After updating from 2.4.4 or older: clean up log files and rotate secrets

Releases up to and including **2.4.4** had Yii's default `logVars` enabled on
every log target. Whenever an error or warning was logged, Yii appended the
complete `$_SERVER` context to `runtime/logs/app.log` — including
`APP_SECRET_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `COOKIE_VALIDATION_KEY`
and `RUNNER_BOOTSTRAP_SECRET` from the container environment. This affected
both the prebuilt images and git checkouts.

The current release disables context dumping, but existing log files still
contain the old entries. After the update:

1. **Truncate the old logs** (inside the app container, or on the host for a
   git checkout where `runtime/` is bind-mounted):

   ```bash
   docker compose exec app sh -c 'for f in /var/www/runtime/logs/app.log*; do : > "$f"; done'
   ```

2. **Rotate the secrets** if those log files were ever copied, attached to a
   bug report, or shipped to a central log store: generate new values for
   `APP_SECRET_KEY`, `COOKIE_VALIDATION_KEY` and `RUNNER_BOOTSTRAP_SECRET` in
   `.env` (`openssl rand -hex 32`), change the database password, then
   `docker compose up -d`. Note that rotating `APP_SECRET_KEY` requires
   re-entering stored credentials, and rotating `RUNNER_BOOTSTRAP_SECRET`
   requires updating every external runner's configuration.

Diagnostics output produced by `bin/diagnose` was never affected — it
redacts secrets before printing.

---

## Rollback

If an update causes problems:

1. **Restore your `.env` backup** if it was modified.
2. **Pin a specific version** by editing the image tags in `docker-compose.yml` (e.g., change `:latest` to `:v2.1.0`).
3. **Restart** with `docker compose up -d`.
4. **Restore your database** if a migration caused issues:

```bash
docker compose exec -T db mariadb -uansilume -p"$DB_PASSWORD" ansilume < backup.sql
```

---

## Automated updates

For automated or scheduled updates, use the `--update` flag in a cron job or CI pipeline:

```bash
# Example: update every night at 3 AM
0 3 * * * cd /opt/ansilume && curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/quickstart | bash -s -- --update >> /var/log/ansilume-update.log 2>&1
```

Consider adding a health check after the update and alerting on failure.

---

## Production updates via Ansible

If you deployed with the Ansible role in `deploy/`, run the playbook again to update:

```bash
cd deploy
ansible-playbook site.yaml -i inventory/production.yaml --ask-vault-pass
```

The role pulls the latest images, restarts services, and waits for health checks. See [deployment.md](deployment.md) for details.

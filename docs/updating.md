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

## After updating from 2.7.x or older: vault scan, vault passwords on runners

**Vault files are scanned.** Every sync now scans the project checkout for
ansible-vault content without decrypting it, and checks whether each job
template's vault password opens the encrypted files the template probably
loads. Existing projects get their first scan at the next sync; manual
projects, which never sync, at their next save or with **Rescan** on the
project page (or `POST /api/v1/projects/{id}/vault/scan`). The project page
shows a **Vault files** card; templates whose vault password does not fit,
that load damaged vault files, or whose check could not cover everything get
a warning on their page, on the launch page and in the API. Warnings never
block a job. The checks of one sync, rescan or save share a time budget of
30 seconds. See [credentials.md](credentials.md#vault-files-in-your-repository).

**Vault passwords on runners.** Each project now has a setting for the vault
settings of the repository's `ansible.cfg`. Existing projects keep "Ansilume
and repository", the behaviour so far; new projects start with "Ansilume
only", where runners ignore the repository's `vault_password_file`,
`vault_identity_list`, `ask_vault_pass` and `vault_id_match`. Before
switching a project, check its vault card: templates that relied on a
password file or script in the repository need their vault password attached
in Ansilume. Every switch is audited.

**Update the runners.** "Ansilume only" needs runners of 2.8 or newer; older
runners keep applying the repository's settings, and the vault card names
them.

- Prebuilt images: `docker compose pull && docker compose up -d` updates the
  bundled runner.
- External runners: deploy the new `ghcr.io/ansilume/ansilume-runner` image.
- Runners installed without Docker: `git pull && composer install --no-dev
  --optimize-autoloader`, then restart the process
  (`sudo systemctl restart ansilume-runner`).
- Dev checkout: after `git pull`, run `docker compose up -d --force-recreate`.
  The containers bind-mount the code, but `app` runs the migrations only when
  it starts, and `queue-worker` and the runners are long-lived processes that
  keep the old code until they restart; without the restart the queue worker
  never scans after a sync. `docker compose restart app queue-worker runner-1
  runner-2` does the same.

No Dockerfile or entrypoint change is needed.

**Fixes that change behaviour:**

- Moving an inventory into another project now needs operator access to the
  new project as well (403 otherwise), in the web UI and with
  `PUT /api/v1/inventories/{id}`.
- A git project without a repository URL fails its sync right away instead of
  staying on "syncing".
- Syncs no longer fail at random when the queue worker's heartbeat interrupts
  git output, and deleted job templates are no longer linted.
- "Parse Inventory" no longer accepts an inventory path that resolves into a
  sibling project directory with the same prefix (for example `projects/12`
  for project 1).
- An inventory with an unknown project id is rejected with a validation error
  (`422` in the API) instead of failing with a server error.
- Launches that skip the launch page (the dashboard's quick launch, relaunch)
  show the template's warnings as a notice on the job page.
- `PUT /api/v1/projects/{id}` with an empty `vault_password_source` is rejected
  (`422`) instead of switching the project to "Ansilume only".

**API contract change:** `Project` gains `vault_password_source` (writable)
and `vault_scanned_at`, and the documented schema now matches the response
(`status` and `last_synced_at`; there is no `updated_at`). New endpoints
`GET /api/v1/projects/{id}/vault` and `POST /api/v1/projects/{id}/vault/scan`.
`POST /api/v1/jobs` answers with the template's `warnings` next to `data`.
Job templates can carry the warnings `vault_password_mismatch`,
`vault_password_missing`, `vault_file_damaged` and `vault_check_incomplete`,
also as `?warning=` filters. Runners carry `capabilities`.

## After updating from 2.6.0 or older: vault passwords, inventories, runner transport

**One vault password per job template.** Ansible gets one vault password from
Ansilume, and the runner used to ignore any further vault credential without
a word. Saving or cloning a template with two is now rejected, in the form and
with `422` in the API. Templates that already have two keep running with the
one that takes precedence; their page says which one is ignored, and their
next save is rejected until one is removed. Changing a credential's type to
Vault Secret is rejected while a template using it already has another vault
password.

**Assigning a vault password to several job templates.** The page of a vault
password has **Assign to job templates**: it replaces another vault password
in place and fixes templates that have two. The API equivalent is
`POST /api/v1/credentials/{id}/job-templates`.

**File and dynamic inventories must come from the template's project.** The
runner checks out only the template's project, so such an inventory of
another project was never read; jobs used a same-named file of the wrong
project or none at all. Saving a new template with such an inventory, or
changing a template's project or inventory so that it ends up with one, is
rejected; existing templates keep running and show a warning. Changing an
inventory's type or project is rejected when templates of another project
would then use it.

**Runner transport is visible.** The runner group page, the Runners API and
`bin/diagnose` show whether each runner connects over HTTPS, over plain HTTP
from a trusted network, or over plain HTTP from outside, where credentials
travel in clear. Trusted networks default to loopback and the private ranges;
set `RUNNER_TRUSTED_NETWORKS` to change them (a value replaces the defaults).
A TLS reverse proxy in front of Ansilume should set `X-Forwarded-Proto` and
`X-Forwarded-For`. No runner image update is needed for any of this.

**Team scoping on job template saves.** Saving a job template now checks the
project it moves to, not only the one it comes from, and cloning needs operate
access to the template's project (view access was enough before). A new or
changed inventory must be one the user may see; an inventory of another team's
project is reported as not existing. Templates that already use such an
inventory keep saving as long as the inventory stays. Unknown projects, runner
groups and approval rules are validation errors instead of server errors.

**Find what needs fixing.** The job template list shows a banner per warning
with a link to the affected templates; the API offers
`GET /api/v1/job-templates?warning=multiple_vault_credentials` and
`?warning=inventory_other_project`, and every job template carries a `warnings`
list.

**API contract change:** `POST` and `PUT /api/v1/job-templates` answer `422`
in the cases above, also for a `PUT` that leaves `credential_ids` out on a
template with two vault passwords, and for unknown or hidden references. A
`PUT` that moves a template into a project the caller may not operate answers
`403`. `PUT /api/v1/credentials/{id}` and `PUT /api/v1/inventories/{id}` answer
`422` for the type changes described above.

## After updating from 2.5.2 or older: credentials

**Jobs no longer run without a credential they need.** A credential that
was deleted after a job was launched, or whose secret cannot be decrypted,
used to be skipped: the job ran without it, for example as the wrong user or
without its vault password. Such a job now fails before it starts. The job
log names the credential and what to do; see
[troubleshooting.md](troubleshooting.md#job-aborted-before-execution-credentials-could-not-be-used).

**The primary credential comes first.** Jobs applied a template's
credentials in the order the credentials were created, not primary first.
An additional SSH key, password or vault credential that was older than the
primary one took `--user`, `--private-key` or `--vault-password-file`. Jobs
now apply the primary credential first, then the additional ones as listed
on the template. Check templates that combine several credentials of the
same kind. Changing `credential_id` over the API also left the old primary
attached; it is now detached.

**SSH passwords reach Ansible.** Username/password credentials only set
`ANSIBLE_SSH_PASS`, which ansible-core never reads, so password logins
failed. The runner now hands the password over in a private file. The fix
runs on the runner, so update the runner image as well: `docker compose
pull` covers the bundled runners, and runners on other hosts need the new
`ansilume-runner` image.

**Credentials need their secret.** Creating a credential without the secret
of its type, or changing its type without entering the new type's secret,
is rejected in the form and the API with `422`. Existing credentials without
a secret keep working as before, and their page flags the secret as
"Missing". A credential whose secret cannot be decrypted, for example after
`APP_SECRET_KEY` changed, is flagged as well.

**Deleting a credential in use needs a second confirmation.** The
credential page lists where a credential is used. **API contract change:**
`DELETE /api/v1/credentials/{id}` answers `409` with `error.used_by` while a
job template, a project or a waiting job uses the credential. Add
`?force=1` to delete it anyway. Scripts that delete credentials must handle
the `409` or pass `force=1`.

Other API changes:

- `POST /api/v1/jobs` accepts `job_template_id`, as documented. `template_id`
  still works. Without either, the answer is now `422` instead of `404`.
- `GET /api/v1/jobs/{id}`, `POST /api/v1/jobs` and the cancel endpoint
  return the job's `credentials`. Job templates return `credential_ids`,
  `credentials` and `runner_group_id`, and accept `credential_ids`.
- Credentials return and accept `env_var_name`. `GET /api/v1/credentials/{id}`
  adds `secret_status` and `used_by`.
- `DELETE /api/v1/job-templates/{id}` is a soft delete, like in the web UI.
  Jobs keep their link to the template.

The audit log now records which runner started a job and with which
credentials, which fields of a credential changed and whether its secret
was replaced, and which credentials were attached to or detached from a
template. None of these entries contain secrets.

## After updating from 2.5.1 or older: vault content stays encrypted on the server

**"Parse Inventory" and lint no longer use a repository's vault settings.**
Until now both honoured the project's `ansible.cfg`. With
`vault_password_file` or `vault_identity_list` pointing at a script, the
server ran that script, which is repository code, inside the app or
queue-worker container. With a committed password file, it decrypted
vaulted `group_vars`, `host_vars` or inventory files and cached the
plaintext, which everyone who can open the inventory could read. Both now
run with a random decoy password; see
[troubleshooting.md](troubleshooting.md#parse-inventory-or-lint-mentions-vault-encrypted-content)
for what the inventory and lint pages show instead.

The update clears every cached parse result; click "Parse Inventory" again
to rebuild it. If a file or dynamic inventory had vaulted `group_vars`,
`host_vars` or an encrypted source whose password was reachable on the
server (a committed password file, a password script, or an
`ANSIBLE_VAULT_PASSWORD_FILE` set on the server), treat those values as
disclosed to everyone with view access and rotate them. If people you do
not fully trust could push to a project repository, also check its vault
password scripts: they ran on the server.

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
`PATH`, locale, proxy and CA settings and `ANSIBLE_*` variables. (Since the
release after 2.5.1 they never get a vault password at all, see above.)

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

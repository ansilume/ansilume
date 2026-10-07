# Troubleshooting

## Diagnostics script

Before digging into specific issues, run the diagnostics script. It collects system state, container health, image versions, connectivity, migration status, and recent logs — all in one go, with secrets automatically redacted.

```bash
./bin/diagnose
# or remotely, without cloning the repo:
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/diagnose | bash
```

To save the output for sharing:

```bash
./bin/diagnose > ansilume-diag.txt 2>&1
```

The output is safe to share — no credentials or tokens are included.

**Stuck and can't figure it out?** Open an issue at [github.com/ansilume/ansilume/issues](https://github.com/ansilume/ansilume/issues) and paste the diagnostics output. It gives us everything we need to help.

---

## Container startup race conditions

When multiple containers (app, runner-1, runner-2, ...) start simultaneously on a shared source volume, concurrent writes can cause problems. Ansilume guards against this with file locks (`flock`) in the entrypoint.

### Composer install

**Symptom:** `vendor/autoload.php` missing or corrupt, class-not-found errors after a fresh `docker compose up`.

**Cause:** Multiple containers ran `composer install` at the same time on the same `vendor/` directory.

**Solution:** The entrypoint uses `flock /var/www/.composer.install.lock` to serialize composer installs. Only one container writes at a time; the others wait and then skip through quickly since dependencies are already installed.

If you still see issues:

```bash
# Force a clean reinstall
docker compose exec app rm -rf vendor/
docker compose restart app
```

### Database migrations

**Symptom:** Migration errors like "table already exists" or deadlocks during initial startup.

**Cause:** Multiple app containers ran `php yii migrate` concurrently.

**Solution:** The entrypoint uses `flock /var/www/.migrate.lock` to ensure only one container runs migrations at a time. Migrations only run in containers started with `php-fpm` (the app container), not in runners.

If migrations are stuck:

```bash
# Check migration status
docker compose exec app php yii migrate/history

# Re-run manually
docker compose exec app php yii migrate --interactive=0
```

## Parse Inventory or lint mentions vault-encrypted content

The server never decrypts vault content, not even with a password file or
script that the repository's `ansible.cfg` names. What you see instead:

- **"Variables from group_vars/ and host_vars/ were not loaded"**: those
  directories hold vault-encrypted files. Hosts and groups are complete;
  only variables written inline in the inventory are shown.
- **`vault-encrypted` badges**: inline `!vault |` values. Their ciphertext
  is not shown or cached.
- **"The inventory source is vault-encrypted"**: the inventory file itself
  is encrypted, so its hosts cannot be listed on the server.
- **Lint badge "not lint-checked: vault-encrypted vars_files"**: the
  playbook loads encrypted files, so ansible-lint could not check it. This
  is not a finding.

Jobs are not affected: the runner decrypts with the job template's vault
credential.

## Job aborted before execution: credentials could not be used

**Symptom:** A job fails right after it was claimed, and its log starts with
`Job aborted before execution: 1 credential(s) could not be used.`

**Cause:** The server could not hand a credential of the job to the runner.
The log names each one:

- **"no longer exists"**: the credential was deleted after the job was
  launched. Attach a replacement to the job template and relaunch.
- **"cannot be decrypted"**: the secret was encrypted with a different
  `APP_SECRET_KEY`, usually because the key changed. Restore the old key, or
  enter the secret again on the credential page, then relaunch. The
  credential page flags such a credential as "Cannot be decrypted".

The job never started, so no credential was used and nothing ran on the
target hosts. The project's SCM credential is checked the same way, shown
as `(scm)` in the message.

## Saving a job template fails: only one vault password

**Symptom:** Saving or cloning a job template fails with `Only one vault
password can be attached to a job template.`, or its page warns that a vault
password is ignored.

**Cause:** Ansible gets one vault password from Ansilume. Templates saved
before 2.7.0 could hold two, and the runner silently used only the first.

**Fix:** Edit the template and keep one vault password, or use **Assign to job
templates** on the page of the vault password you want, which replaces the
other one. The template list shows all affected templates through its warning
filter, and the API through `GET /api/v1/job-templates?warning=multiple_vault_credentials`.

## Saving a job template fails: the inventory belongs to another project

**Symptom:** `File and dynamic inventories must belong to the job template's
project`, or the template page warns about an inventory of another project.

**Cause:** The runner checks out only the template's project and looks for a
file or dynamic inventory there. With an inventory of another project, jobs
read a file of the same name in the wrong project, or none at all, while
"Parse Inventory" previews the other project's hosts.

**Fix:** Pick an inventory of the template's project, or a static inventory,
which works with any project. Older templates keep running with a warning;
find them with the template list's warning filter or
`GET /api/v1/job-templates?warning=inventory_other_project`.

## Runner group page warns about plain HTTP

**Symptom:** A runner shows "Plain HTTP" in the Transport column, and the
runner group page shows a red warning.

**Cause:** The runner talks to the server over plain HTTP from outside the
trusted networks, so claim responses carry decrypted credentials in clear.

**Fix:** Set the runner's `API_URL` to an `https://` address behind a TLS
reverse proxy that sets `X-Forwarded-Proto` and `X-Forwarded-For`, then
rotate the credentials it received. If the runner really sits in a trusted
network, set `RUNNER_TRUSTED_NETWORKS` to all trusted networks, that one
included. A value replaces the defaults, so also list the ranges of the
bundled runners and of your reverse proxy, for example
`127.0.0.0/8,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7,198.51.100.0/24`.
`bin/diagnose` shows the list in use; see
[runners.md](runners.md#plain-http-warning).

## Runners show "unknown" name/group

**Symptom:** Runner logs show `Runner 'unknown' started. Group: 'unknown'.`

**Cause:** The runner failed to register or authenticate with the app. Common reasons:
- `RUNNER_BOOTSTRAP_SECRET` mismatch between app and runner
- App container not ready when runners start (DB not migrated yet)
- Stale cached auth token from a previous run

**Solution:**

```bash
# Check runner logs for auth errors
docker compose logs runner-1 --tail 50

# Restart runners (they auto-recover and re-register)
docker compose restart runner-1 runner-2
```

## Site looks down in the browser, but the containers are healthy

**Symptom:** `docker compose ps` shows everything healthy, `bin/diagnose` reports
no issues, but the browser spins forever or shows "This site can't be reached".

**Cause:** Ansilume serves plain **http://** out of the box — there is no TLS
listener unless you put a reverse proxy in front of it. Modern browsers with
*HTTPS-First* / *HTTPS-Only* mode silently upgrade a typed `host:8080` to
`https://host:8080`, which nothing answers.

**Solution:** Open the URL with an explicit `http://` prefix, or terminate TLS
in a reverse proxy and set `APP_URL` to the `https://` address. For
production, the proxy route is the recommended one.

## A bare `curl` on the URL prints nothing

**Symptom:** `curl http://host:8080/` prints an empty line and exits 0, which
looks like a broken install.

**Cause:** `/` answers with a `302` redirect to `/login`. The redirect body is
empty, so curl has nothing to print. The install is fine.

**Solution:** Look at the headers or follow the redirect:

```bash
curl -IL http://host:8080/          # shows the 302 and the final 200
curl -s http://host:8080/health     # {"status":"ok", ...}
```

The quickstart runs exactly these checks at the end of an install or update
and exits non-zero if any of them fail.

## App returns 502 Bad Gateway

**Symptom:** Nginx returns 502 when accessing the UI.

**Cause:** PHP-FPM is not running or not ready yet (still running composer install or migrations).

**Solution:** Wait a moment for the entrypoint to finish, then check:

```bash
# Check if php-fpm is running
docker compose exec app ps aux | grep php-fpm

# Check entrypoint progress
docker compose logs app --tail 20
```

Runners that start during this window see the same 502 from nginx. They log
`Server is not ready for runner registration yet ... retrying in 5s` and keep
trying for about a minute before giving up and letting the container restart
policy retry — no action is needed as long as the app container comes up.

## Health endpoint reports unhealthy

**Symptom:** `/health` returns `"healthy": false` even though the app seems to work.

**Cause:** The health check verifies both the database connection and that at least one worker process has reported in recently. If no workers are running or have not yet checked in, the endpoint reports unhealthy.

**Solution:**

```bash
# Check worker status
docker compose ps

# Ensure runners are up
docker compose up -d runner-1 runner-2
```

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

## Workflow step failed without a job

**Symptom:** A workflow run fails at a step that has no job. The run page
shows a message under the step's status, and the API returns it as
`steps[].error` of `GET /api/v1/workflow-jobs/{id}`, for example
`Not launched: user #7, whom this workflow runs as, may not launch job template "Deploy web" (#15).`

**Cause:** A workflow runs its job steps as the user who launched it; for a
trigger, the user who generated the token (the workflow's creator for tokens
generated before Ansilume recorded that). Before each job step starts,
Ansilume checks that this user is still active, holds `workflow.launch` and
has operator access to the step's project. When the check fails, the step
fails without a job, the refusal is audited as `workflow.step.denied` (with
the job template and project), and the workflow fails without following its
branches. Common reasons: the user was disabled, lost the permission or left
the team while the run waited at a pause or approval step, the team's access
to the project was lowered to viewer, or the run was launched before the
update to team-scoped workflows by a user without operator access to every
step's project. The other messages:

- **"The job template of this step no longer exists."**: the step's job
  template was deleted.
- **"The approval rule of this step no longer exists."**: the step's approval
  rule was deleted.
- **"The job could not be launched; see the application log."**: launching
  the job failed. The reason is in `runtime/logs/app.log` of the app
  container.
- **"Not run: unknown step type "…"."**: the step's type is none of job,
  approval and pause, compared exactly (`JOB` or `job ` with a trailing space
  are unknown too). Older versions stored such steps: the API did not
  validate steps, and the add-step form stored `true` from a JSON request as
  `1`.

**Fix:** Give the user back what they lack (re-enable them, restore
`workflow.launch`, or operator access to the step's project), or let a user
who may operate every job step launch the workflow again. For a trigger,
revoke its token and generate a new one as such a user. For a missing job
template or approval rule, edit the workflow and replace the step; for an
unknown step type, remove the step and add it again with its type.

## Trigger answers 403 "Launch refused."

**Symptom:** A call to `/trigger/fire`, `/trigger/{token}` or
`/trigger/fire-workflow` answers `403` with `{"error": "Launch refused."}`,
and nothing is launched.

**Cause:** A trigger runs as the user who generated its token; tokens
generated before Ansilume recorded that run as the template's creator. The
trigger card on the template page names that user, and the API returns it as
`trigger_user_id` of `GET /api/v1/job-templates/{id}` or
`GET /api/v1/workflow-templates/{id}`, both only to users who may change the
template (the update permission and operator access that generating a token
needs, see below). A call is refused while the user is disabled, lacks
`job.launch` (`workflow.launch` for a workflow), or has no operator access to
the template's project (the project of every job step for a workflow), for
example after leaving the team. The first two apply to installations without
teams too. Generating a token needs `job-template.update`
(`workflow-template.update` for a workflow) and operator access, not the
launch permission, so a token generated by a user without that permission is
refused on every call. The caller learns no details; the audit log has them:

- `trigger.denied` for a job template, with the reason `not_permitted`
  (disabled or without `job.launch`) or `no_project_access`.
- `workflow.launch.denied` with source `trigger` for a workflow, with the job
  templates the user may not operate in `job_template_ids` (empty when only
  the user's status or permission is the problem).

**Fix:** Restore the user's access, or revoke the token and generate a new one
as a user who may launch the template; the trigger then runs as that user.
Give the new URL to the system that calls the trigger.

## Schedule did not launch (schedule.launch.denied)

**Symptom:** A schedule starts no job when it is due. The audit log has
`schedule.launch.denied` for it, and the `schedule.failed_to_launch`
notification, if one is set up, says for example
`Schedule #12 (Nightly deploy) not launched: user #7, whom it runs as, is not an active user allowed to launch job template #15.`
or `... has no operator access to the project of job template #15.`

**Cause:** A schedule launches its job template as the user who created it.
At every due time Ansilume checks that this user is active, holds
`job.launch` and may operate the template's project, with or without teams.
A refused schedule stays enabled and moves on to its next due time, where the
check runs again.

**Fix:** Re-enable the user, or give them back `job.launch` or operator access
to the project. Otherwise recreate the schedule as a user who may launch the
template and delete the old one: the creator of a schedule cannot be changed,
and editing the schedule keeps it. The schedule page shows the creator under
**Created by**, and the API returns it as `created_by` of
`GET /api/v1/schedules/{id}`.

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

## Template warns that its vault password does not open files

**Symptom:** The template page, the launch page or the API shows
`vault_password_mismatch` ("The vault password ... does not open ...") or
`vault_password_missing`.

**Cause:** The project's last vault scan found encrypted files or values next
to the template's inventory or playbook (or in its `vars_files`) that the
template's vault password does not open, without decrypting anything. With one
vault file per environment, the template usually has the password of another
environment, or its inventory points to the wrong environment.

**Fix:** Attach the vault password of the right environment, or fix the
inventory. The project's **Vault files** card lists, per template, the files
the password does not open. If the repository changed since the last sync,
sync the project: every sync scans again, and **Rescan** only reads the
checkout of the last sync. For a manual project whose files you changed by
hand, click **Rescan**. If the project uses "Ansilume and repository" and the
repository's `ansible.cfg` sets `vault_id_match`, Ansible tries the template's
password only on vaults without a vault ID or with the ID `default`; the
warning then says so, and the card marks those files with `vault_id_match`.
The check cannot know which groups a play targets, so it may name
`group_vars` files a run never loads; the warning never blocks a job.

## Template warns about damaged vault files or an incomplete vault check

**Symptom:** The template shows `vault_file_damaged` ("Ansible cannot read ...
whatever the password") or `vault_check_incomplete` ("The vault check of this
template is incomplete"); its row on the project's vault card says "damaged
vault files" or "not fully checked".

**Cause and fix:**

- `vault_file_damaged`: a vault file or inline value the template loads is
  damaged, for example by trailing spaces or an editor that re-wrapped it. The
  **Problem** column of the vault card says what is wrong. Encrypt the value
  again with `ansible-vault` and commit it.
- `vault_check_incomplete`, "the scan stopped at a limit": the checkout has
  more than 20000 files or 2000 encrypted values, or scanning took longer than
  10 seconds; vendored collections or virtualenvs in the repository are the
  usual cause. Remove them from the repository.
- `vault_check_incomplete`, "ran out of time": the checks of one sync, rescan
  or save share 30 seconds, and the repository has very many or very large
  encrypted files. The next sync or rescan checks the remaining templates first.
- `vault_check_incomplete`, "not valid UTF-8": file names Ansilume cannot
  store, so it cannot check those files. Rename them.

## Jobs fail to decrypt after switching to "Ansilume only"

**Symptom:** After setting a project's vault passwords to "Ansilume only",
jobs fail with `Attempting to decrypt but no vault secrets found` or
`Decryption failed`.

**Cause:** The repository's `ansible.cfg` supplied the password (a
`vault_password_file`, a script or vault identities), and runners now ignore
it.

**Fix:** Attach the vault password to the job templates (or use **Assign to
job templates** on the vault credential), or switch the project back to
"Ansilume and repository". The project's vault card shows which templates lack
a matching password.

## Vault card lists runners older than 2.8

**Symptom:** The vault card of an "Ansilume only" project lists runners that
apply the repository's vault settings anyway.

**Cause:** Those runners run an image older than 2.8; they do not know the
setting and keep the repository's vault settings for every project.

**Fix:** Update the runner image (prebuilt: `docker compose pull && docker
compose up -d`; external runners: the new `ansilume-runner` image; runners
without Docker: `git pull`, `composer install --no-dev --optimize-autoloader`
and restart the process; dev checkout: `docker compose up -d
--force-recreate`, which also restarts the queue worker and lets `app` run the
migrations). Without `runner-group.view` the card shows only how many runners
are affected.

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

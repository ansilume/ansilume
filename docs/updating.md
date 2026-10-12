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

# 2. Rebuild the images and recreate every container, so that the app runs
#    the migrations and every service loads the new code and configuration
docker compose up -d --build --force-recreate
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

## After updating from 2.8.1 or older: encrypted password answers, trigger tokens in a header, logs without secrets

Answers to password fields of job template surveys are now stored only
encrypted, and `$encrypted$` stands in their place on pages, in the API and
in the stored command line. Trigger tokens go in a request header, and nginx
no longer writes tokens from URLs to its access log. The application log no
longer records every SQL statement with its values, session IDs, or trigger
URLs that hold a token. The update encrypts the answers that earlier
versions stored in plaintext. What to do:

1. Now, whether you update or not: look for users that an end-to-end test
   seed left on the installation (see **End-to-end test commands are
   locked**).
2. Before the update, if job templates have password survey fields, check
   `APP_SECRET_KEY` (see **Before you update: APP_SECRET_KEY**).
3. Update the runners (see **Update the runners**).
4. Git checkout: recreate the containers right after `git pull` (see
   **Database and deployment**).
5. Read what the update printed and fix the job templates it lists (see
   **The update encrypts the stored answers**). Change the passwords that
   were given as survey answers before the update where that matters (see
   **What the update cannot encrypt**).
6. Move the callers of triggers to the header, then generate new tokens for
   those that sent the token in the URL (see **Send trigger tokens in a
   header**).
7. Purge old log files (see **Purge old logs**).
8. Give custom roles that should re-launch jobs `job.launch` (see
   **Re-launch needs job.launch**).

**Before you update: APP_SECRET_KEY.** Password answers are encrypted with
`APP_SECRET_KEY`, like credentials. When any job template has a password
field, the update checks first that the key can encrypt, which needs at
least 16 characters. If it cannot, the update changes nothing, prints

```
APP_SECRET_KEY is not set or shorter than 16 characters, so the stored survey password answers cannot be encrypted. Set APP_SECRET_KEY in .env to the value your credentials were encrypted with, then start the containers again. Nothing was changed.
```

and stops, and the app container does not start until the key is fixed.
Check the key in the install directory before the update. This prints `1`
when the key has at least 16 characters, and never prints the key:

```bash
grep -c '^APP_SECRET_KEY=.\{16,\}' .env
```

A shorter key never encrypted anything, because credentials need the same
16 characters. If credentials were stored with an earlier value, put that
value back; otherwise generate a new one with `openssl rand -hex 32`. Then
start the containers again: `docker compose up -d` (git checkout:
`docker compose up -d --build --force-recreate`, see **Database and
deployment**). Installations without password fields do not need the key
for the update. Afterwards, keep `APP_SECRET_KEY` as it is:
changing it makes the stored answers unreadable, as it does with
credentials.

**Password answers are stored encrypted.** A password field in a survey
used to change only the input of the launch form. Its answer was stored in
plaintext with the job's extra vars, shown on the job page and returned by
the jobs API to everyone who could see the job, viewers included, copied by
re-launch, and passed to `ansible-playbook` on its command line. Now the
value of an extra var whose top-level key is the name of a password field of
the job's template is a password answer, wherever it comes from: the survey,
the advanced overrides of the launch form, `POST /api/v1/jobs`, a trigger, a
schedule, a re-launch, or the template's own extra vars. Ansilume stores it
only encrypted (`job.secret_extra_vars`, `schedule.secret_extra_vars`) and
decrypts it only on the server, when a runner claims the job. Everywhere
else the value is `$encrypted$`: the extra vars and the execution command of
the job page, of its live log view and of the jobs API, the runner payload
stored with the job, the schedule page, form and API, and the template page.
Audit entries name the keys of carried answers, never their values.

- Some launches that 2.8.1 ran are refused now, with the reason: the launch
  form shows it, `POST /api/v1/jobs` and job triggers answer `422`, a
  workflow step fails with it as its error (a workflow trigger still answers
  `201`), and a scheduled run fails to launch with it. These are:
  - `$encrypted$` sent as a password answer, which happens when the extra
    vars of an earlier job are copied into a new launch; 2.8.1 used the
    placeholder as the password:
    `The value of "db_password" is the placeholder $encrypted$; send the real value.`
  - a job template with a password field whose name cannot carry an answer
    (see **The update encrypts the stored answers**);
  - extra vars or answers that JSON cannot hold: a number such as `1e999`,
    which PHP reads as infinity, or text that is not UTF-8 in a form field,
    such as a survey answer. 2.8.1 dropped the extra vars and survey answers
    of such a launch and ran the job with the template's extra vars only:
    `The extra vars cannot be stored: they contain a number too large for JSON.`,
    or `The password answers cannot be stored: ...` for a password answer.
    Extra vars sent as JSON text that is not valid JSON, text that is not
    UTF-8 included, are still ignored, as in 2.8.1, and so is a trigger body
    that is not valid JSON.
- Job output is whatever the playbook prints. Runners of this release
  replace known answers with `********` in it, as far as they can (see
  **Update the runners**); older runners do not.
- If `APP_SECRET_KEY` changes, stored answers cannot be decrypted any more.
  Jobs that wait in the queue then fail before they start with
  `The job's encrypted survey answers cannot be decrypted (was APP_SECRET_KEY changed?). Launch the job template again and enter the password answers.`,
  and re-launches and schedules need the answers entered again.
- Anyone who may change a job template, or push to the repository of its
  project, can make the stored answers of its jobs and schedules reach a
  playbook of their choosing, as with credentials. Stored answers never
  follow a schedule to another job template, nor a re-launch to another
  project or inventory.

**The update encrypts the stored answers.** Migration
`m000081_000000_encrypt_survey_password_answers` runs when the app container
starts. It removes the default value of every password field (launches never
used it), encrypts the answers of schedules and of unfinished jobs (pending,
queued, waiting for approval, running), where the extra vars, the runner
payload and the stored command now hold `$encrypted$`, and lists the job
templates that need an operator. An answer is the value under the exact name
of a password field of the job's template, also of a field whose name cannot
carry an answer (see below); the first line of the output counts the
templates of such fields among those with password fields. It works only on
job templates, schedules and unfinished jobs, so it usually takes well
under a second (about 7,500 rows a second were measured). Watch it with
`docker compose logs -f app`, and if `docker compose up -d` gave up waiting
for the app container, run it again once the migration is done. An
interrupted run keeps what it committed, and the next start finishes the
rest. The output has counts and IDs only, for example:

```
    > Survey password answers: 14 job templates checked, 2 of them with password fields.
    > Job templates whose password fields lost their default value: 1 (#7).
    > Job templates with a password field whose name cannot carry an answer; they cannot be launched until the field is renamed in the survey editor: 1 (#9).
    > Schedules that launch those job templates: 2 (#3, #4).
    > Job templates whose Extra Vars hold a password field's name, a stored default of the password; remove the key from the Extra Vars: 1 (#5).
    > Schedules: 3 checked, 2 encrypted.
    > Unfinished jobs: 40 checked, 12 encrypted.
    > Finished jobs of these job templates: 1234. Their stored answers are encrypted in the background by maintenance/run in the schedule-runner container, a few seconds per minute; it logs its progress as "survey-secret-scrub".
```

Act on what it lists:

- **A password field whose name cannot carry an answer** (empty, a number,
  with `[` or `]` in it, with a space at its start or end, or with a
  character anywhere in it that is not printable ASCII: a control character
  such as a line break or a tab, or a character outside ASCII such as a
  non-breaking space, a byte order mark, a zero-width space or a letter like
  `ä`): its template refuses every launch with
  `Survey field "db[pw]" has a name that cannot be used for a password answer; rename it in the survey before launching.`
  The message shows characters that are not printable ASCII escaped as in
  JSON, such as `"db_password\u00a0"` for a trailing non-breaking space.
  Answers stored under the field's exact name are encrypted all the same
  and show as `$encrypted$`. Rename the field in the survey editor and
  change the playbook and the schedules that use the old name: in each
  schedule, enter the password under the new name and remove the old key,
  whose answer stays encrypted under the old name. The editor removes
  spaces, line breaks, tabs, non-breaking spaces and byte order marks around
  a name when it saves; retype a name that holds any other such character.
- **Extra Vars that hold a password field's name**: the template keeps a
  plaintext default of that password. The template page shows it as
  `$encrypted$`, but the edit form shows it to everyone who may edit the
  template. Remove the key from the Extra Vars and enter the value at launch,
  or as a schedule's answer.
- **Job templates skipped because their survey is not valid JSON**: fix the
  survey in the editor or with `PUT /api/v1/job-templates/{id}`.
- **Rows left unchanged** (stored encrypted answers that cannot be
  decrypted, a value that would not fit its column, an error): they keep
  their plaintext, see **What the update cannot encrypt**.
- **Stored commands that were cleared**: the job's stored command did not
  contain its extra vars as stored, so it was emptied instead of keeping an
  answer.

**Finished jobs are encrypted in the background.** The history can be long,
so `maintenance/run`, which the schedule-runner runs every minute, encrypts
the answers of finished jobs in the same way, 5 seconds a minute, from where
the previous minute stopped. About 35,000 jobs a minute were measured, so a
million finished jobs take about half an hour. Until it has reached a job,
the job page and the jobs API still show that job's answers in plaintext.
Re-launching such a job works: the new job stores the answers encrypted.
Follow the progress with `docker compose logs -f schedule-runner`, one line
a minute while it works:

```
[maintenance] ran: survey-secret-scrub (checked=35000, encrypted=1200, undecryptable=0, too_long=0, failed=0, command_cleared=0, done=0)
```

It is finished at `done=1` and quiet afterwards. The IDs of rows it left
unchanged are in the schedule-runner's application log, as
`survey-secret-scrub:` lines (git checkout: `runtime/logs/app.log`;
prebuilt: `docker compose exec schedule-runner grep survey-secret-scrub /var/www/runtime/logs/app.log`).
It waits while migrations are pending or `APP_SECRET_KEY` cannot encrypt.
Its position and its done mark are kept in Redis; if Redis loses them, it
starts over, which is safe. Restoring a database backup from before the
update runs `m000081` again, which starts it over as well. A backup taken
after `m000081` ran does not: the jobs it had not encrypted yet when the
backup was taken would keep their plaintext answers, as Redis still holds
the position and done mark from before the restore. Restore such a backup
in this order:

```bash
docker compose stop schedule-runner
# restore the database, see Rollback below
docker compose exec app php yii maintenance/reset-survey-secret-scrub
docker compose start schedule-runner
```

The command prints `The survey password scrub starts over at the next maintenance run.`
It deletes only the position and the done mark, and jobs already encrypted
are skipped, so it is safe to run at any time. With the schedule-runner
stopped, a pass that is under way cannot store its old position again, and
no schedule launches a job meanwhile. Do not flush the cache instead
(`php yii cache/flush cache`): the cache shares its Redis database with the
sessions and the queue, so that logs every user out and drops queued work.

**What the update cannot encrypt.** It recognizes answers by the names the
password fields have now. These keep their plaintext:

- jobs whose job template was purged, as no survey says which of their keys
  are passwords;
- answers of fields that were renamed, removed, or changed to another type
  since the job ran;
- answers of fields whose name cannot carry an answer that were stored
  under another key than the exact name: the name a caller sent instead
  (such as `db_password` for a field named `db_password` with a trailing
  space), or a name PHP made of it (from the launch form, such as `db[pw`
  for `db[pw]`); answers under the exact name are encrypted;
- the rows the update listed as left unchanged;
- job output in which a playbook printed an answer;
- database backups and binary logs from before the update, and old log
  files (see **Purge old logs**).

Until now every user who could see a job, the viewer role included, could
read its password answers, and the console log recorded the answers of
scheduled jobs. Treat the passwords given as survey answers before the
update as known to those users, and change them where that matters.

To find rows that keep a plaintext answer, use these read-only queries with
the database client of the `db` container
(`docker compose exec db sh -c 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'`).
They select IDs only. Replace `<template id>` and `<field name>`, for
example with a field's old name after a rename:

```sql
-- Jobs of a job template whose extra vars hold a value for <field name>
-- that is not the placeholder. For jobs of purged job templates, use
-- "job_template_id IS NULL" instead.
SELECT id FROM job
WHERE job_template_id = <template id>
  AND JSON_VALID(extra_vars)
  AND JSON_CONTAINS_PATH(extra_vars, 'one', '$."<field name>"')
  AND BINARY JSON_UNQUOTE(JSON_EXTRACT(extra_vars, '$."<field name>"')) <> BINARY '$encrypted$';

-- The same for schedules
SELECT id FROM schedule
WHERE job_template_id = <template id>
  AND JSON_VALID(extra_vars)
  AND JSON_CONTAINS_PATH(extra_vars, 'one', '$."<field name>"')
  AND BINARY JSON_UNQUOTE(JSON_EXTRACT(extra_vars, '$."<field name>"')) <> BINARY '$encrypted$';

-- Job templates whose own extra vars hold <field name>
SELECT id FROM job_template
WHERE JSON_VALID(extra_vars)
  AND JSON_CONTAINS_PATH(extra_vars, 'one', '$."<field name>"');
```

Keep the `BINARY JSON_UNQUOTE(...)` form: on MariaDB, comparing
`JSON_EXTRACT(...)` with `JSON_QUOTE('$encrypted$')` also matches rows that
hold the placeholder.

**Update the runners.** Runners of this release report the capability
`secret_extra_vars`. They get the password answers of a job apart from its
command and pass them to `ansible-playbook` in a file of the job's private
directory (`--extra-vars @<file>`, mode 0600, removed after the job), so the
answers show neither in the process list of the runner host nor in the
command line that `ansible-playbook` prints at `-vvvv`. They also replace
known answers with `********` in the job output and in task names before
sending them; answers shorter than 4 characters and values that Ansible
prints in another form stay visible (see
[runners.md](runners.md#password-survey-answers)). If such a runner cannot
create the job's directory or write the file (temp directory missing,
read-only or full), the job fails and does not run, with
`The runner could not create the job's private directory or files in its temp directory.`
or, when only the file could not be written,
`The runner could not write the survey answers file.` Updated runners also
bring the two runner fixes listed under **Fixes that change behaviour**: a
private directory for the files of each job, and job output without gaps.

Older runners keep working. The server decides per claim, so a runner group
can mix old and new runners. Old runners get the answers inline in the
command, as before, protected as far as possible: the `-v` flags are capped
at `-vvv`, and the command starts with `/usr/bin/env ANSIBLE_VERBOSITY=0`,
which also overrides a `verbosity` in the repository's `ansible.cfg` and an
`ANSIBLE_VERBOSITY` from a Token credential, because ansible-core prints
every command-line argument above verbosity 3. The answers still show in the
process list of the runner host while the job runs. When the job asked for
verbosity 4 or 5, the job log ends with

```
[ansilume] Verbosity was lowered from 4 to 3: this runner does not support encrypted survey answers and would print them at higher verbosity. Update the runner image.
```

and the `job.started` audit entry has `verbosity_capped_from`. Jobs without
password answers run as before on every runner. Runners older than 1.0 build
the command themselves: they get the capped verbosity but not
`ANSIBLE_VERBOSITY=0`, so update them.

- Prebuilt images: `docker compose pull && docker compose up -d` updates the
  bundled runners.
- Ansible deploy role: running the role for the new version updates its
  runners.
- External runners: deploy the new `ghcr.io/ansilume/ansilume-runner` image.
- Runners installed without Docker: `git pull && composer install --no-dev
  --optimize-autoloader`, then restart the process
  (`sudo systemctl restart ansilume-runner`). Before the restart:
  - add `PrivateTmp=true` to the `[Service]` section of the systemd unit
    and run `sudo systemctl daemon-reload`, so that the files the runner
    keeps in its temp directory are its own; until a runner is updated,
    this is what keeps its job files away from other users of the host (see
    [runners.md](runners.md#running-without-docker));
  - check that PHP has the `posix` extension: `php -m | grep -x posix`
    prints `posix`. Without it, the runner removes no job directory that a
    killed runner process left behind, and names them at every start
    instead;
  - set `zend.exception_ignore_args = On` in the runner's php.ini, as the
    images do (see **Purge old logs**).

  Once every runner on the host runs this release, remove the files that
  runners of 2.8.1 or older left in their temp directory, as their owner or
  root: `sudo rm -f -- /tmp/ansilume_inv_*.yml /tmp/ansilume_tasks_*.ndjson`
  (in a runner's `TMPDIR` instead, if it has one), and the inventories that
  runners older than 1.0 wrote without `.yml` (`ansilume_inv_<unique>`).
  They are static inventories, which can hold host passwords, and task
  records, whose task names can carry answers, and those runners left them
  readable by every local user. Updated runners do not remove them, as a
  runner of 2.8.1 or older on the host may still be using one. A runner
  that shares their temp directory names those that the command removes at
  start (see [runners.md](runners.md#password-survey-answers)); one with
  `PrivateTmp=true` does not see the host's `/tmp`.
- Git checkout: the runners keep the old code in memory until they restart;
  recreating the containers restarts them (see **Database and deployment**).

`GET /api/v1/runners` lists `secret_extra_vars` in `capabilities` of a
runner once it runs the new code, and the `job.started` audit entry of each
job says `runner_supports_secret_extra_vars`.

**Re-launch reuses the encrypted answers.** Re-launching a job with password
answers reuses them encrypted: nobody enters or sees them. This holds only
while the job template still uses the project and the inventory the job ran
with; otherwise the re-launch is refused with
`The template's project or inventory changed since this job ran; launch the template and enter the password answers.`
Answers that cannot be decrypted give
`The original job's encrypted survey answers cannot be decrypted; launch the template and enter the password answers.`
A finished job from before the update that the background encryption has
not reached yet re-launches with the plaintext it holds, which the new job
stores encrypted. The `job.launched` audit entry of a re-launch names the
job it repeats (`relaunch_of`) and the keys of the answers it carried
(`carried_secret_keys`).

**Re-launch needs job.launch.** It needed `job.cancel` before, while the
button checked `job.launch`. The default roles have both or neither; give
`job.launch` to custom roles that should re-launch. Only a finished job whose
job template still exists can be re-launched, as the button always showed: a
crafted request could re-launch a queued or running job, or a job of a
deleted template, which then stayed queued for good. Such requests now get
`Job #N cannot be re-launched in status "queued".` or
`Original template no longer exists.`

**Schedules keep their answers encrypted.** The schedule page, the edit
form and the schedules API show password answers as `$encrypted$`: every
schedule response of the API now has `extra_vars`, the text the edit form
shows, or `null`. Send that text back, in the form or in a `PUT`, to keep
every answer: leave a placeholder to keep its answer, replace it to change
the answer, remove the key to drop it, and empty the extra vars to clear
them all. A `PUT` without `extra_vars` keeps them. Changing the job template
of a schedule needs the answers entered again, as stored answers never move
to another template. All of this holds for the answers of every password
field, also of one whose name cannot carry an answer; the schedules of its
job template fail to launch until the field is renamed. A value whose key
became a password field after the schedule was saved shows as `$encrypted$`
at once; it is stored encrypted at the schedule's next run, or at the next
save that sends the extra vars, as the form always does. Such a run writes
a `schedule.updated` audit entry with `secret_keys_sealed` (names only). A
scheduled run fails and logs, with the usual `schedule.failed_to_launch`
notification:

- `Schedule #12 (Nightly deploy) failed to launch: Its stored password answers cannot be decrypted (was APP_SECRET_KEY changed?); edit the schedule and enter them again.`
  Every run fails until the answers are entered again.
- `Schedule #12 (Nightly deploy) failed to launch: The schedule changed while its run was being launched; it runs again at its next time.`
  The schedule held such a value and was moved to another job template, or
  deleted, while the run was being launched. Nothing was launched; the next
  run uses the schedule as it was saved.

The `schedule.created` and `schedule.updated` audit entries now record
`job_template_id`, on updates `previous_job_template_id`, and the names of
the answers kept, replaced, added and dropped.

**Scheduled runs wait for the update.** While migrations are pending,
`schedule/run` launches nothing and prints
`Schedules not run: 1 database migration(s) pending; they run once the update has finished.`
Runs that fall due meanwhile launch once after the update. This covers the
schedule-runner of prebuilt installations, which can start before the app
container has migrated, and git checkouts, whose schedule-runner runs the new
code as soon as `git pull` brings it.

**Survey definitions are checked when they change.** Saving a job template
whose survey changed, in the survey editor or with the API, now checks the
survey: new and renamed fields need a variable name (letters, digits and
underscores, not starting with a digit), names must be unique, types must be
known, and a password field cannot have a default. Names are compared as
stored: `db_password` with a space after it is another name than
`db_password`. A password field whose name is not printable ASCII without a
space at its start or end (see **Act on what it lists**) cannot keep that
name once the survey changes, and a field with such a name cannot become a
password field. Messages show the characters of a name that are not
printable ASCII escaped as in JSON, such as `\u00a0` for a non-breaking
space, so that invisible ones can be found. Templates saved before keep
saving and cloning while their survey stays as it is. The survey editor
shows these errors, which were invisible before, removes whitespace around
names, offers no default for password fields, and clears the default when a
field becomes a password field. A field of a type Ansilume does not know
shows as Text in the editor, as the launch form already treated it, and is
saved so.

A job template's Extra Vars may not hold a top-level key that is the name of
one of its password fields, as its value would be a plaintext default of the
password: `Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.`
This is checked when a save changes the Extra Vars or the names of the
password fields (one added, renamed, removed, or retyped to or from
password); other survey edits, such as defaults, labels or options, do not
count. The Extra Vars change when their JSON value does:

- A key, or a value with its type, counts: `"1"` and `1` differ, and so do a
  list and an object, and a whole number beyond the 64-bit range and the
  same digits as text, such as `12345678901234567890` and
  `"12345678901234567890"`.
- Spacing, line breaks and the order of the keys do not count. A key written
  twice counts with its last value, as at a launch.
- A number written another way that PHP reads as the same number within the
  64-bit range (`-9223372036854775808` to `9223372036854775807`) does not
  count, such as `1.0` for `1`, `1e2` for `100` or `9007199254740994.0` for
  `9007199254740994`. Two numbers written with a fraction or an exponent are
  the same when PHP reads them as the same floating-point number, such as
  `0.50` for `0.5`. Such a whole number counts as unchanged, but a launch
  passes the form with a fraction or an exponent as PHP writes the
  floating-point number, in the fewest digits that read back as it. Without
  the sign, from 2^54 (`18014398509481984`) on, that can be another whole
  number, such as `18014398509481990` for `18014398509481992.0`, and from
  10^17 on it has an exponent and reaches the playbook as a floating-point
  number, such as `1.0e+18` for `1e18`.
- Beyond the 64-bit range, a whole number written without a fraction or an
  exponent is the same only as the same digits: `1e20` for
  `100000000000000000000` is a change.
- A whole number that a client rounded counts, such as `9007199254740993`
  sent back as `9007199254740992`. So does a number written with a fraction
  or an exponent that PHP reads as another whole number: PHP reads it as the
  nearest floating-point number, which above 2^53 (`9007199254740992`) can
  be another whole number, so `9007199254740993.0` for `9007199254740993` is
  a change.

Templates saved before the rule keep saving such edits with the key in
place; to change their Extra Vars, remove the key and enter the value at
launch.

**The Extra Vars editor keeps the text as typed.** The Extra Vars editor of
the job template form, the schedule form and the Advanced Overrides of the
launch page rewrote the Extra Vars as soon as the page opened, so every save
from the web UI, and every launch of a template without a survey, sent them
rewritten: whole numbers beyond 2^53 rounded (`9007199254740993` became
`9007199254740992`), a number too large for JSON (`1e999`) became `null`,
`1.0` became `1`, and keys that look like whole numbers moved first. While
the text did not parse, the form sent the last version that did. Now a form
sends the Extra Vars as stored until they are edited, and in JSON mode as
typed; only line breaks arrive as CRLF, as browsers send them, which leaves
the JSON value as it is. A form whose Extra Vars do not parse is not
submitted, and the editor says why. YAML mode and the switch between JSON
and YAML still go through JavaScript numbers: they refuse a number that
JavaScript cannot hold exactly instead of changing it (see
[troubleshooting.md](troubleshooting.md#extra-vars-editor-the-form-is-not-submitted-or-yaml-is-not-converted)),
and YAML mode still writes `1.0` as `1`; edit in JSON mode to keep a number
as written. YAML mode reads YAML 1.2, while Ansible reads its own YAML files
as YAML 1.1, so some plain values are sent with another meaning than Ansible
gives them there, without a message: `0644` as the number `644`, which
Ansible reads as octal (`420`), `yes`, `no`, `on` and `off` as text, which
Ansible reads as booleans, and `1:30` as text, which Ansible reads as `90`.
A date such as `2026-10-11` is sent as the text `2026-10-11T00:00:00.000Z`.
Quote such values, write booleans as `true` and `false`, or use JSON mode.
Browsers load the new editor at once, as the address of its script now
changes with its content.

**Send trigger tokens in a header.** Triggers take the token in the
`X-Trigger-Token` header, or as `Authorization: Bearer <token>`:

```bash
curl -X POST -H "X-Trigger-Token: <token>" -H "Content-Type: application/json" \
  -d "{}" https://ansilume.example.com/trigger/fire
```

The template pages show only this form. The URL forms,
`/trigger/fire?token=<token>`, `/trigger/fire-workflow?token=<token>` and,
for job templates only, `POST /trigger/<token>`, keep working but are
deprecated: URLs end up in access logs, in proxies in front of Ansilume and
in the logs of the systems that call the trigger. A header wins over a token
in the URL, and a token in the URL wins over Bearer, so a caller that sends
an unrelated `Authorization` header keeps working.

Every launch through a trigger is now audited as `trigger.fired`, with how
the token was sent (`transport`: `header`, `bearer` or `url`) and the job or
workflow run it launched; refusals (`trigger.denied`, and
`workflow.launch.denied` with source `trigger`) record the transport too.
This lists the templates whose callers still send the token in the URL:

```sql
-- Templates whose trigger was called with the token in the URL
SELECT object_type, object_id, COUNT(*) AS calls,
  FROM_UNIXTIME(MAX(created_at)) AS last_call
FROM audit_log
WHERE action IN ('trigger.fired', 'trigger.denied', 'workflow.launch.denied')
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.transport')) = 'url'
GROUP BY object_type, object_id
ORDER BY object_type, object_id;
```

Without database access, look at `GET /api/v1/audit-logs?action=trigger.fired`
(needs `user.view`; 25 entries a page).

Once a caller sends the header, generate a new token for its template, on
the template page or with `POST /api/v1/job-templates/{id}/trigger-token`
(`/api/v1/workflow-templates/{id}/trigger-token` for workflows), and give
the caller the new token: the old one stops working at once. A token that
was sent in a URL is in the access logs of nginx and of any proxy in front
of it, in log shippers, possibly in the application log, and in the logs of
the calling system. Generating rebinds the trigger: it runs as the user who
generates the token, so generate it as the user the trigger should run as
(see **Triggers run as the user who generated the token** in the 2.8.0
section below).

**nginx no longer logs tokens from URLs.** The access log of the bundled
nginx uses the new format `ansilume_masked`, with the fields of nginx's
`combined` format. From the first `token=` query parameter on, the rest of
the URL becomes `token=[REDACTED]`, which also covers password-reset links,
and the path of `/trigger/<token>` becomes `/trigger/[REDACTED]`, in the
request and in the referrer. Lines written before the update stay as they
are (see **Purge old logs**). The error log of nginx cannot be masked: it
repeats the request line on request errors, so only the header keeps a token
out of it, and out of proxies in front of nginx (see
[deployment.md](deployment.md#https--tls)).

- Prebuilt images: the new `ghcr.io/ansilume/ansilume-nginx` image has the
  configuration; `docker compose pull && docker compose up -d` applies it.
- Git checkout: nginx reads `docker/nginx/default.conf` only when it starts,
  and `docker compose up -d --build` does not recreate it; recreate the
  containers after `git pull` (see **Database and deployment**).
- Ansible deploy role: running the role again renders the new configuration.
- Your own nginx configuration in place of `docker/nginx/default.conf`: copy
  the four `map` blocks and the `log_format` from the top of
  `docker/nginx/default.conf` into the `http` context, outside `server {}`,
  and end your `access_log` line with `ansilume_masked`.

**Other changes to triggers:**

- `GET`, and any method but `POST`, on `/trigger/<token>` answers `405`; it
  answered `404` before. Monitors that probe the URL may notice.
- A call without a usable token (no header, an empty `?token=`, or
  `token[]=…`) answers `400` `Missing trigger token.`; an empty `?token=`
  answered `404` and sent the `webhook.invalid_token` notification before.
- A job trigger whose launch is refused (see **Password answers are stored
  encrypted**) answers `422` with the reason, `{"error": "..."}`; such calls
  launched a job in 2.8.1. A workflow trigger answers `201` for them, and
  its run fails at the start step with the reason.
- A workflow trigger whose user may not launch now answers `403` and is
  audited for a workflow without steps too; it answered `500`.
- Every other path under `/trigger/` answers `404` without writing the path,
  which could hold a token, to the application log.
- The trigger card shows a new token once; the page used to print it a
  second time, at the top.
- Revoking a template that has no token no longer writes a
  `*.trigger-token.revoked` audit entry.
- `bin/diagnose` also masks `trigger/<token>` in the log lines it prints.

**Purge old logs.** Earlier versions wrote secrets into logs that stay after
the update:

- The console log (`runtime/logs/app.log` of the app, queue-worker and
  schedule-runner containers) recorded every SQL statement with its values,
  among them the extra vars and password answers of the jobs that schedules
  launched, runner payloads, and the statements of migrations. It no longer
  records these statements (`yii\db\Command`); errors, warnings and
  Ansilume's own lines stay, and the message of a database error can still
  quote its statement.
- Every line of the web application log carried the session ID
  (`[IP][user ID][session ID]`), so anyone who could read `app.log` could
  take over those sessions. The prefix is `[IP][user ID]` now; log parsers
  that expect three fields must expect two.
- A launch that failed with a database error logged the SQL statement with
  every value; it logs the SQLSTATE and the driver's error code now.
- The stack traces of errors in `app.log` showed the first 15 characters of
  every text argument of the calls in them, and the error pages of debug
  mode all of it: passwords of users and of LDAP, password-reset and API
  tokens, TOTP and recovery codes, the runner token and
  `RUNNER_BOOTSTRAP_SECRET`, credential secrets, vault passwords and the
  Redis password could be among them. Ansilume's own methods now leave such
  values out (`Object(SensitiveParameterValue)`), and the images set
  `zend.exception_ignore_args = On`, so that traces show no arguments at
  all, not even those of PHP's own functions and of Yii. Servers and
  runners that run without these images: set it in their php.ini. A git
  checkout gets it from `docker/php/php.ini` once its containers are
  recreated (see **Database and deployment**).
- The access log of nginx, which goes to Docker's log of the nginx
  container, holds trigger tokens and password-reset tokens from URLs.
- On installations that run with `YII_ENV=dev`, as git checkouts set up
  from `.env.example` do, the data of the debug toolbar in `runtime/debug/`
  holds request headers, among them `X-Trigger-Token` and `Authorization`,
  and new trigger tokens. It stores `****` for them now. It still keeps
  cookies, URLs, form posts and the server environment with the `.env`
  secrets, so never expose such an installation.

Prebuilt images: the update recreates the app, queue-worker, schedule-runner
and nginx containers from the new images, which removes their old log files
and Docker logs; nothing is left to purge in them. Git checkout, and the
deploy role installed from source: the containers share `runtime/` with the
install directory, so truncate the old files once and drop the debug data:

```bash
docker compose exec -T app sh -c 'for f in /var/www/runtime/logs/app.log*; do if [ -f "$f" ]; then : > "$f"; fi; done; rm -rf /var/www/runtime/debug/*'
```

This also clears the IDs the background encryption has logged so far; the
queries under **What the update cannot encrypt** find those rows again.
Recreating the containers (see **Database and deployment**) drops the Docker
log of nginx and PHP's error log `/var/log/php_errors.log` in the PHP
containers, whose stack traces showed text arguments as those in `app.log`
did. In every installation, purge copies elsewhere yourself: log shippers
and Docker logging drivers (journald, syslog, Loki, …), central log stores,
backups of the hosts, reverse proxies in front of Ansilume, the CI logs of
systems that call triggers, and PHP's error log on servers and runners that
run without these images.

**End-to-end test commands are locked.** `php yii e2e/seed`,
`php yii e2e/teardown` and every other `e2e/*` command now refuse to run
unless `YII_ENV` is `dev` or `test`, with exit code 78 and

```
Refused: the e2e commands run only with YII_ENV dev or test, and YII_ENV is "prod" here.
```

The seed creates users whose passwords are public in the Ansilume
repository, among them `e2e-admin`, a superadmin, and the teardown deletes
every record named `e2e-*`. Earlier versions ran both anywhere. Check now,
whether you update or not, that no seed ran on your installation:

```sql
SELECT id, username, is_superadmin, status FROM user WHERE username LIKE 'e2e-%';
```

Disable or delete every user it lists, `e2e-admin` first (status `10` is
active), on the Users page or with the API, and look in the audit log for
what they did. Do not run `e2e/teardown` to clean up: it deletes every record
whose name starts with `e2e-`. Installations that run with `YII_ENV=dev`, as
git checkouts set up from `.env.example` do, are not protected; production
must run with `YII_ENV=prod`.

**YII_DEBUG is read strictly.** `1`, `true`, `on` and `yes`, in any case,
turn debug mode on; anything else turns it off. Before, every value but an
empty one and `0` turned it on, so `YII_DEBUG=false`, `off` or `no` showed
error pages with stack traces and opened the test-only web endpoints under
`/e2e/`. The shipped configurations use `0` and `1` and behave as before;
check `.env` for other values.

**Fixes that change behaviour:**

- The survey editor ran a survey field's text that contained `</script>` as
  script code in the browser of everyone who opened the template's edit form
  (stored cross-site scripting). It now escapes it, as every inline script
  of the web UI does now.
- Job output with `<!--<script` in it no longer hides the rest of the job
  page.
- A launch that failed with a database error showed the SQL statement with
  every value, password answers included. It now shows
  `Job launch failed: the job could not be saved.`
- The launch form accepts only its own fields: extra vars, limit, verbosity,
  check mode and survey answers. Anything else in a crafted request is
  ignored, and a list where a single value belongs is ignored instead of
  causing a server error.
- Re-launching a job that is not finished, or whose job template was
  deleted, is refused (see **Re-launch needs job.launch**).
- Runners of this release give every job a new directory in their temp
  directory that only the runner's user can open (mode `0700`), and keep
  the job's own files there, each readable by that user only: a static
  inventory, which can hold host passwords, the callback plugin's task
  records, whose task names can carry answers, and the survey answers file.
  The runner removes the directory with everything in it when the job ends.
  Before, the static inventory and the task records were written straight
  into the temp directory and were `0644`, so where a runner shares its
  temp directory with other users of the host, as a runner without Docker
  can, those users could read them while the job ran and could interfere
  with jobs that use a static inventory; and the inventory stayed behind
  when a credential could not be set up. Updated runners name such files
  that they find in their temp directory at start, but do not remove them
  (see **Update the runners**). Playbooks now see the job's
  directory as `inventory_dir` of a static inventory. If a runner cannot
  create the directory or these files, the job fails before it runs with
  `The runner could not create the job's private directory or files in its temp directory.`
  Until a runner without Docker is updated, `PrivateTmp=true` (see
  **Update the runners**) keeps these files away from other users.
- Saving a job template or a schedule, in the web UI or with the API,
  refuses extra vars that hold a number too large for JSON, such as `1e999`:
  `The extra vars cannot be stored: they contain a number too large for JSON.`
  So does `POST` or `PUT /api/v1/workflow-templates` for a step's
  `extra_vars_template`, given as JSON text or as an object, as
  `steps[<n>]: ...`. 2.8.1 stored such extra vars, and launches with them
  are refused now (see **Password answers are stored encrypted**), except
  that it stored a step given such an object without its extra vars. A job
  template or schedule that already holds such a number gets the error at
  its next save, whatever else changes, and cloning such a template fails:
  write the value as a string or as a smaller number.
- Runners of this release no longer lose job output: a log chunk that ended
  inside a multi-byte character, such as `ü` at an 8 KB boundary, was
  dropped together with the next chunk, and a chunk with bytes that are not
  UTF-8 was dropped whole. Such bytes now arrive as `�` (U+FFFD).

**API contract change:**

- `GET /api/v1/jobs`, `GET /api/v1/jobs/{id}`, `POST /api/v1/jobs` and
  `POST /api/v1/jobs/{id}/cancel` show password answers as `$encrypted$` in
  `extra_vars` and `execution_command`. The `execution_command` of a job
  with password answers that a runner without `secret_extra_vars` claimed
  starts with `/usr/bin/env ANSIBLE_VERBOSITY=0` and has at most `-vvv`.
- `POST /api/v1/jobs` answers `422` with the reason for the launches it
  refuses now (see **Password answers are stored encrypted**); in 2.8.1 it
  answered `201` for them. A number too large for JSON is refused with
  `extra_vars` as an object or as JSON text. Text that is not UTF-8, which
  only a form-encoded body can carry, is refused in `extra_vars[<key>]`
  fields, while `extra_vars` as JSON text that is not valid JSON, such text
  included, is ignored, as before, and the job runs with the template's
  extra vars. Other launch failures still answer `500`.
- Every response of the schedules API has the new field `extra_vars`, the
  text the edit form shows, password answers as `$encrypted$`, or `null`;
  clients that refuse unknown fields must accept it. `POST` and
  `PUT /api/v1/schedules` store password answers in `extra_vars` encrypted.
  `$encrypted$` keeps a stored answer, and new `422` answers name the
  problem, for example `"db_password" has no stored value; enter the real value.`
  A `PUT` without `extra_vars` keeps the answers, unless it changes
  `job_template_id`; then it answers `422` until `extra_vars` comes with the
  answers.
- `POST` and `PUT /api/v1/job-templates` answer `422` for survey definitions
  and extra vars that break the rules above, when the request changes what
  a rule checks. The spec now documents `survey_fields`, a list of
  `SurveyField` or the same list as JSON text.
- `POST` and `PUT /api/v1/job-templates` and `/api/v1/schedules` answer
  `422` `The extra vars cannot be stored: they contain a number too large for JSON.`
  for extra vars that hold such a number, a `PUT` also when it leaves stored
  extra vars that hold one as they are. `POST` and
  `PUT /api/v1/workflow-templates` answer `422` `steps[<n>]: ...` for a
  step's `extra_vars_template` with such a number, given as JSON text or as
  an object; for the object, 2.8.1 answered `201` or `200` and stored the
  step without its extra vars.
- New: `POST` and `DELETE /api/v1/job-templates/{id}/trigger-token` and
  `/api/v1/workflow-templates/{id}/trigger-token`. `POST` generates a token
  and answers `201` with `{"data": {"token": "...", "trigger_user_id": 7}}`
  and `Cache-Control: no-store`; it is the only response with the token.
  `DELETE` revokes it and answers `200` with `{"data": {"revoked": true}}`,
  also when there was none. They need the permissions of the web UI and are
  audited the same way, with source `api`.
- Triggers take the token in `X-Trigger-Token` or `Authorization: Bearer`;
  the `token` query parameter and `/trigger/{token}` are deprecated. An empty
  `?token=` answers `400` instead of `404`, methods other than `POST` on
  `/trigger/{token}` answer `405` instead of `404`, and refused job launches
  answer `422` (see **Other changes to triggers**).
- Runners report the capability `secret_extra_vars`.
- Audit: the new action `trigger.fired`; `transport` in `trigger.denied` and
  in `workflow.launch.denied` with source `trigger`;
  `runner_supports_secret_extra_vars`, and `verbosity_capped_from` when
  capped, in `job.started`; `relaunch_of` (re-launch) or `schedule_id`
  (schedule) with `carried_secret_keys` in `job.launched`.

**Database and deployment.** Two migrations run when the app container
starts. `m000080_000000_add_secret_extra_vars` adds `secret_extra_vars` to
`job` and `schedule`, a column at the end of the table, which MariaDB 10.11
and MySQL 8 add instantly. `m000081_000000_encrypt_survey_password_answers`
encrypts the stored answers, see above. Code of this release that runs
before them works with the old tables: launches without password answers
work, and a launch with one fails with
`The database update has not finished; run the migrations.` instead of
storing the answer in plaintext. No new setting is needed in `.env`.

- Prebuilt images and quickstart: `docker compose pull && docker compose
  up -d`, or quickstart `--update`, is all.
- Git checkout: right after `git pull`, run
  `docker compose up -d --build --force-recreate`. The containers serve the
  new code from the bind mount at once, but `app` runs the migrations only
  when it starts, the queue worker and the runners keep the old code until
  they restart, and nginx reads its configuration only at start, while
  `docker compose up -d --build` alone does not recreate it. The app,
  queue-worker, schedule-runner and runner containers mount the single file
  `docker/php/php.ini`, which now sets `zend.exception_ignore_args = On`,
  and such a mount keeps showing the old file after `git pull` replaces it,
  until the container restarts. `--force-recreate` recreates all of them.
  A restart is not enough: it keeps each container's own files, which
  recreating drops: the Docker log of nginx, PHP's error log
  `/var/log/php_errors.log` in the PHP containers, whose stack traces from
  before the update can show arguments (see **Purge old logs**), and what
  runners of 2.8.1 left in their `/tmp` (see **Files of older runners** in
  [runners.md](runners.md)).
- Ansible deploy role: run the playbook again; it renders the new nginx
  configuration and recreates the containers.

## After updating from 2.8.0 or older: team scoping for workflows, approvals and triggers

Team scoping now covers workflows, approval requests, analytics and the
dashboard, and schedules, triggers and workflow steps check the user they run
as. Admins, superadmins and installations whose projects are not assigned to
any team see the same workflows, approvals and reports as before. Two changes
apply to every installation: the checks of the user that schedules, triggers
and workflow steps run as (see **Schedules and triggers of users who may not
launch stop**), and dashboard panels that stay empty for users without the
matching permission. Every installation should also review its trigger
tokens, schedules and workflow steps (see **Review trigger tokens, schedules
and workflow steps**).

**Workflows belong to the projects of their job steps.** A workflow template
has no project of its own. Seeing a workflow and its runs now needs view
access to the project of every job step; changing, deleting, launching,
resuming and canceling it, and generating or revoking its trigger token, need
operator access to every one. Only job steps count: workflows with only
approval and pause steps stay visible to everyone with the permission. Older
versions could store a job template on approval and pause steps too; it
counts for nothing, and the update removes it, as well as approval rules
stored on job and pause steps; steps of another type keep both (see **Review
trigger tokens, schedules and workflow steps**). A deleted job template keeps
counting with its
project, but a job step whose job template was purged, as happens when its
project is deleted, fails closed: only admins see that workflow until one of
them removes the step. A workflow that combines projects of different teams
is therefore hidden from members who cannot see all of them; split it, or
give the team access to every project it uses, after checking that each of
its steps belongs there (see **Review trigger tokens, schedules and workflow
steps**). Users who may see but not
change a workflow get a notice on its page instead of the edit and launch
buttons, and new job steps offer only the job templates the user may operate.

**Workflow runs check every job step when it starts.** A run's job steps run
as the user who launched it; for a trigger, the user the trigger runs as.
When a job step is about to start, that user must still be active, hold
`workflow.launch` and have operator access to the step's project. Otherwise
the step fails without a job, the run's page says why (`steps[].error` in the
API), the refusal is audited as `workflow.step.denied`, and the workflow fails
without following its branches. This also applies to runs that were in flight
during the update: a run launched by someone who may not operate every step's
project fails at the next such step. Launches are refused up front for the
same reasons (`403`, audited as `workflow.launch.denied` with the job
templates concerned); the refusal names those job templates only to users who
may see the workflow. A step whose job template or approval rule no longer
exists fails the workflow as well; a missing approval rule used to leave the
run running for good.

**Approvals follow the job.** An approval request is visible to users who may
view the job's project; the request of a workflow approval step follows its
workflow. Deciding needs `approval.decide`, a place on the rule's approver
list, and that view access. Approvers without it no longer count toward the
rule's threshold, so check the approver lists of your approval rules: a
request whose rule has fewer approvers with access than it requires is
rejected at the first vote, and one with none stays pending until an approver
with access is added to the rule and decides it. A rule timeout does not end
it either, because the stock deployments do not run the timeout check
(`php yii approval/check-timeouts`). In the web UI, deciding on a request
that is already resolved, or voting twice, shows a message instead of an
error page.

**Analytics and the dashboard are scoped.** Analytics reports, their CSV
export and the analytics API count only the jobs, workflow runs and approval
requests the user may see; the project and template filters of the analytics
page offer only visible ones. Every list and counter of the dashboard is
scoped the same way, quick launch offers only templates and workflows the
user may launch, and panels for a permission the user lacks stay empty, for
example upcoming schedules without `job.launch`. Runner counts stay global.

**Triggers run as the user who generated the token.** Until now a trigger
launched as the template's creator, whoever had generated the token. A token
generated now runs as the user who generates it, and every call checks that
this user is active, holds `job.launch` (`workflow.launch` for workflows) and
may operate the template's project (the project of every job step for
workflows). Otherwise the call answers `403 {"error": "Launch refused."}` and
is audited as `trigger.denied` (job templates) or `workflow.launch.denied`
with source `trigger` (workflows). Tokens generated before the update keep
running as the template's creator; review them as described below. The
trigger card on the template page names the user a trigger runs as and says
when the token predates the update; the API returns that user as
`trigger_user_id` of the job or workflow template, next to
`has_trigger_token`. Only users who may change the template see the card and
get these two fields: `job-template.update` (`workflow-template.update` for
workflows) and operator access to the template's project (the project of
every job step), as generating a token needs. Team members whose team only
views the project no longer see the card.

**Schedules and triggers of users who may not launch stop.** These checks
apply to installations without teams as well. A schedule launches only while
its creator is active, holds `job.launch` and may operate the project of its
job template. Otherwise it does not launch when it is due: every time,
`schedule.launch.denied` is audited and the `schedule.failed_to_launch`
notification is sent. Triggers check the same for the user they run as, and
workflow runs for the user who launched them (with `workflow.launch`).
Schedules and trigger tokens of disabled users, or of users moved to a role
without `job.launch`, therefore stop launching after the update. A schedule's
creator cannot be changed, not even by editing the schedule: re-enable the
user, restore the permission, or recreate the schedule as a user who may
launch the template. For a trigger, generate a new token as such a user. After
the update, look in the audit log for `schedule.launch.denied`,
`trigger.denied`, `workflow.launch.denied` with source `trigger` (refused
workflow triggers) and `workflow.step.denied` (workflow runs that stopped at
a job step).

**Schedules use templates the user may operate.** Creating or editing a
schedule needs operator access to the project of its job template, and the
template dropdown lists only those templates. A template the user sees but
may not operate is refused (`403`); an unknown, deleted or invisible one is
reported as "The selected job template does not exist." (`422` in the API).
The schedule page names the creator its jobs run as; the API returns it as
`created_by`.

**Review trigger tokens, schedules and workflow steps.** A token generated
before the update by someone other than the template's creator still runs
with the creator's rights, for example a team operator's token on a template
an admin created. Until this release, web forms could also set a template's
trigger token and creator and a schedule's creator (see **Fixes that change
behaviour**). Such tokens and schedules can keep launching after the update,
and neither the trigger card nor the schedule page can show how they got
there; the audit log can. After the update, run the following read-only
queries with the database client of the `db` container, or with your own
client for an external database:

```bash
docker compose exec db sh -c 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

The first two list every job template and workflow template whose trigger
token predates the update:

```sql
-- Trigger tokens generated before the update, job templates
SELECT t.id, t.name, t.created_by AS runs_as,
  (SELECT c.user_id FROM audit_log c
    WHERE c.action = 'job-template.created' AND c.object_type = 'job_template'
      AND c.object_id = t.id
    ORDER BY c.id LIMIT 1) AS created_entry_by,
  (SELECT IF(g.action = 'job-template.trigger-token.generated', g.user_id, NULL)
     FROM audit_log g
    WHERE g.action IN ('job-template.trigger-token.generated',
                       'job-template.trigger-token.revoked')
      AND g.object_type = 'job_template' AND g.object_id = t.id
    ORDER BY g.id DESC LIMIT 1) AS token_generated_by,
  (SELECT COUNT(*) FROM audit_log u
    WHERE u.action = 'job-template.updated' AND u.object_type = 'job_template'
      AND u.object_id = t.id AND u.user_id <> t.created_by) AS edits_by_others
FROM job_template t
WHERE t.trigger_token IS NOT NULL AND t.trigger_token_created_by IS NULL
  AND t.deleted_at IS NULL
ORDER BY t.id;

-- The same for workflow templates
SELECT t.id, t.name, t.created_by AS runs_as,
  (SELECT c.user_id FROM audit_log c
    WHERE c.action = 'workflow-template.created'
      AND c.object_type = 'workflow_template' AND c.object_id = t.id
    ORDER BY c.id LIMIT 1) AS created_entry_by,
  (SELECT IF(g.action = 'workflow-template.trigger-token.generated', g.user_id, NULL)
     FROM audit_log g
    WHERE g.action IN ('workflow-template.trigger-token.generated',
                       'workflow-template.trigger-token.revoked')
      AND g.object_type = 'workflow_template' AND g.object_id = t.id
    ORDER BY g.id DESC LIMIT 1) AS token_generated_by,
  (SELECT COUNT(*) FROM audit_log u
    WHERE u.action = 'workflow-template.updated'
      AND u.object_type = 'workflow_template'
      AND u.object_id = t.id AND u.user_id <> t.created_by) AS edits_by_others
FROM workflow_template t
WHERE t.trigger_token IS NOT NULL AND t.trigger_token_created_by IS NULL
  AND t.deleted_at IS NULL
ORDER BY t.id;
```

- `runs_as`: the template's creator, whom the trigger runs as.
- `created_entry_by`: who created the template, from its
  `job-template.created` or `workflow-template.created` entry. Empty for
  templates without one, such as the selftest and demo templates that
  Ansilume seeds itself.
- `token_generated_by`: who generated the current token, from the newest
  `job-template.trigger-token.generated` or
  `workflow-template.trigger-token.generated` entry, unless a
  `*.trigger-token.revoked` entry follows it. Empty when there is no such
  entry: the token was not generated on the template page.
- `edits_by_others`: the number of `job-template.updated` or
  `workflow-template.updated` entries by users other than `runs_as`.

Revoke every token in these lists on the template page, generate a new one as
the user the trigger should run as, and give the new URL to the system that
calls the trigger; the old URL stops working. A new token runs as the user who
generated it and is checked on every call. If you keep a token, keep only one
whose `created_entry_by` and `token_generated_by` both equal `runs_as` and
whose `edits_by_others` is 0: only then does the audit log show that the
template's creator generated it and nobody else changed the template.

Schedules run as their creator. The third query lists the schedules whose
creator is not the user of their `schedule.created` entry (or that have
none), and those that other users edited (`schedule.updated` entries by users
other than `runs_as`):

```sql
-- Schedules whose creator differs from their created entry, or that
-- someone other than their creator edited
SELECT * FROM (
  SELECT s.id, s.name, s.enabled, s.created_by AS runs_as,
    (SELECT c.user_id FROM audit_log c
      WHERE c.action = 'schedule.created' AND c.object_type = 'schedule'
        AND c.object_id = s.id
      ORDER BY c.id LIMIT 1) AS created_entry_by,
    (SELECT COUNT(*) FROM audit_log u
      WHERE u.action = 'schedule.updated' AND u.object_type = 'schedule'
        AND u.object_id = s.id AND u.user_id <> s.created_by) AS edits_by_others
  FROM schedule s
) r
WHERE r.created_entry_by IS NULL OR r.created_entry_by <> r.runs_as
   OR r.edits_by_others > 0
ORDER BY r.id;
```

Recreate the schedules in this list as a user who may launch their job
template, and delete the old ones; a schedule's creator cannot be changed.
The audit entries do not say what an edit changed, so a schedule that only
other users edited (`created_entry_by` equals `runs_as`) may stay if its job
template and extra vars on the schedule page are what its creator would run.

Until this release, the add-step form could also add a step to another
workflow (see **Fixes that change behaviour**), and steps added on a
workflow's page before the update have no audit entry (this release adds
`workflow-template.step.added`). The fourth query lists the steps of every
workflow with their job template, the template's project and when the step
was created. Look closely at workflows that have a trigger token
(`has_trigger` is 1) or that admins launch, and remove the steps nobody
expects from their workflow:

```sql
-- Steps of every workflow, with their job template and its project
SELECT w.id AS workflow_id, w.name AS workflow,
  w.trigger_token IS NOT NULL AS has_trigger,
  s.id AS step_id, s.step_order, s.name AS step, s.step_type,
  s.job_template_id, j.name AS job_template,
  j.project_id, p.name AS project,
  s.approval_rule_id,
  FROM_UNIXTIME(s.created_at) AS step_created
FROM workflow_template w
JOIN workflow_step s ON s.workflow_template_id = w.id
LEFT JOIN job_template j ON j.id = s.job_template_id
LEFT JOIN project p ON p.id = j.project_id
WHERE w.deleted_at IS NULL
ORDER BY w.id, s.step_order, s.id;
```

A `step_type` other than `job`, `approval` and `pause`, such as `1`, was
stored by an older version, and runs now fail at that step (see **Fixes that
change behaviour**). The type must match exactly: `JOB` or `job ` with a
trailing space count as unknown too, although the database compares them as
equal to `job`, so a plain `NOT IN` misses them. Such a step keeps its job
template and approval rule, which tell what it was meant to be: remove it and
add it again with that type and target. To list only such steps, add this
condition to the query's `WHERE`; the cast compares the type exactly, as runs
do:

```sql
  AND CAST(s.step_type AS BINARY) NOT IN ('job', 'approval', 'pause')
```

Without database access, revoke and regenerate every trigger token
(`has_trigger_token` of the job and workflow templates in the API, which
only callers who may change the template get, such as admins), compare the
schedules' `created_by` with their `schedule.created` and `schedule.updated`
entries on the Audit Log page or at
`GET /api/v1/audit-logs?action=schedule.created` (needs `user.view`; 25
entries a page, add `&page=2` and so on), where `user_id` is who acted and
`object_id` the schedule, and check the steps of every workflow in `steps` of
`GET /api/v1/workflow-templates/{id}`, where `step_type` must be exactly
`job`, `approval` or `pause` (the workflow page shows a stored `Job` like
`job`).

**Fixes that change behaviour:**

- Web forms no longer accept `created_by`, `trigger_token` or
  `trigger_token_created_by` for job and workflow templates,
  `workflow_template_id` for workflow steps, or `created_by` for schedules;
  the API ignores them too. A crafted form could set them before, choosing
  whom a template's trigger or a schedule ran as, or adding a step to another
  workflow.
- Jobs without a job template were visible to everyone. The placeholder job of
  a workflow approval step now follows its workflow, and a job whose template
  was purged with its project is visible only to admins.
- `POST` and `PUT /api/v1/workflow-templates` stored steps without
  validating them, with any job template ID, and a failure while writing
  could leave a workflow without its steps. Steps are now validated first,
  and the template and its steps are written in one transaction.
- Older versions could store a workflow step whose type is none of job,
  approval and pause: the API did not validate steps, and the add-step form
  stored `true` from a JSON request as `1`. A run that reached such a step
  stayed running until it was canceled. The add-step form now refuses such
  types, and a run that reaches a step stored that way fails there with
  `Not run: unknown step type "1".` (with the stored type). The steps query
  above finds these steps; runs that reached one before the update stay
  running until they are canceled.
- `PUT /api/v1/schedules/{id}` stores the next run of a changed cron
  expression; before, the schedule ran once more at the old time.
- A cron expression with a negative step, such as `*/-5 * * * *`, is refused.
  Older versions accepted it from the API, and evaluating it stopped the
  scheduler at that schedule on every run, so the schedules after it did not
  launch. The scheduler now skips such a schedule, and any schedule whose
  values it cannot evaluate, and runs the others. To find them:
  `SELECT id, name, cron_expression FROM schedule WHERE cron_expression LIKE '%/-%';`
  and correct their expression on the schedule page.
- Canceling a job that waits for approval, or a workflow run at an approval
  step, leaves the approval request pending. Deciding it later changed the
  canceled job: approving queued the job again, so it ran, and a workflow
  took the approval step's success route after its failure route. A decision
  now changes only a job that still waits for approval; the request itself
  stays pending until someone decides it.
- Deleting a user whom other records still refer to (jobs they launched, rows
  they created, a trigger token they generated) ended in a server error, and
  `DELETE /api/v1/users/{id}` removed the user's roles before it failed. The
  web UI now shows a message instead, and the API answers `409`; the user and
  their roles are kept. Disable such users instead of deleting them. Users
  whose deletion through the API failed this way before the update lost their
  roles, so their schedules and triggers stop launching after the update; give
  them their role back, or replace their schedules and tokens. Deleting a user
  in the web UI now also removes their role assignments, which used to stay
  behind in the database; the update removes those left behind by earlier
  deletions.

**API contract change:**

- `POST` and `PUT /api/v1/workflow-templates`: every step needs a `name`
  (`422` instead of a generated "Step N"). A job step needs `job_template_id`,
  an approval step `approval_rule_id`; `job_template_id` is ignored on approval
  and pause steps. Job templates that do not exist for the caller answer
  `422`, visible ones the caller may not operate `403`, both naming them in
  `error.job_template_ids`. `steps` must be a list; `steps: []` removes all
  steps, a missing or null `steps` keeps them. As before, replacing the steps
  also deletes the step records of every earlier and running run of the
  workflow, and a running run then stops advancing until it is canceled;
  removing a step on the workflow page does the same for that step. Let runs
  finish, or cancel them, before changing the steps.
- Workflow templates, workflow jobs and approvals are team-scoped: lists and
  `meta.total` leave out what the caller may not see, single resources answer
  `403`. `POST /api/v1/workflow-templates/{id}/launch`,
  `POST /api/v1/workflow-jobs/{id}/cancel` and `/resume` answer `403` without
  operator access to the project of every job step, checked before the run's
  state. A refused launch names the job templates in
  `error.job_template_ids` only to callers who may see the workflow; others
  get `You may not launch this workflow.` without them.
- `GET /api/v1/workflow-jobs/{id}` adds `steps[].error`.
- `POST /api/v1/approvals/{id}/approve` and `/reject` answer `403` for a
  request the caller may not see, before checking the approver list. A
  caller who is not an eligible approver, or who decides on a request that is
  already resolved, now gets the usual error shape,
  `403 {"error": {"message": "You are not eligible to decide on this request."}}`,
  instead of `{"name": "Forbidden", "message": "...", "code": 0, "status": 403}`.
  Clients that read the top-level `message` must read `error.message`.
- The analytics endpoints count only what the caller may see.
- `POST /api/v1/jobs/{id}/cancel` checks access before the job's state: a
  caller without operator access gets `403`, not `409`, for a finished job.
- `POST` and `PUT /api/v1/schedules`: `403` for a job template the caller sees
  but may not operate, `422` "The selected job template does not exist." for
  an unknown, deleted or invisible one; `PUT` also checks a new
  `job_template_id`. `created_by` is ignored.
- Schedules return `created_by`, the user they run as, and job templates
  `created_by`. Job and workflow templates return `has_trigger_token` and
  `trigger_user_id`, the user a trigger runs as (`null` without a token), only
  to callers who may change the template: `job-template.update`
  (`workflow-template.update`) and operator access to its project (the
  project of every job step). For everyone else both keys are absent. The
  token itself is never returned.
- `DELETE /api/v1/users/{id}` answers `409` instead of `500` when other
  records still refer to the user, and keeps the user's roles.
- `PUT /api/v1/users/{id}` with `is_superadmin: false` on the only superadmin
  answers `422` "Cannot demote the only superadmin." and changes nothing. The
  web UI already refused this, and `DELETE` refuses to delete the only
  superadmin.
- `/trigger/fire`, `/trigger/{token}` and `/trigger/fire-workflow` answer
  `403 {"error": "Launch refused."}` when the user the trigger runs as may not
  launch. `extra_vars` of `/trigger/fire-workflow` reach the start step only,
  as before; the documentation used to say every job step.
- The OpenAPI spec now describes workflow templates, workflow jobs, approvals
  and triggers with named schemas, and documents `POST /trigger/{token}`.

**Database and runners.** Three migrations run when the app container starts.
`m000077_000000_add_workflow_team_scoping` adds
`workflow_job_step.error_message` and `trigger_token_created_by` to
`job_template` and `workflow_template`.
`m000078_000000_clear_step_targets_of_other_types` removes the job template
of approval and pause steps and the approval rule of job and pause steps,
comparing the type exactly; steps of another type keep both.
`m000079_000000_remove_orphaned_role_assignments` removes the role
assignments of users who no longer exist, which the web UI left behind when
it deleted a user; they counted as users of the role and as approvers of
role-based approval rules. It prints how many it removed, and nothing else
needs to be done. Like `created_by`, `trigger_token_created_by` keeps a user
from being deleted while a template's current trigger token is theirs;
disable the user instead, or revoke the token first. Deleting a job or
workflow template revokes its token. No runner update is needed. Dev
checkout: after `git pull`, run `docker compose up -d --force-recreate` (or
`docker compose restart app queue-worker`) so that `app` runs the migrations
and the long-lived queue worker loads the new code.

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

A backup taken after the update to encrypted survey password answers
(migration `m000081`) had run needs the schedule-runner stopped during the
restore and `php yii maintenance/reset-survey-secret-scrub` afterwards, see
**Finished jobs are encrypted in the background** under
[After updating from 2.8.1 or older](#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets).

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

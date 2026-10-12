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

## Job aborted before execution: survey answers cannot be decrypted

**Symptom:** A job fails right after it was claimed, and its log says
`The job's encrypted survey answers cannot be decrypted (was APP_SECRET_KEY changed?). Launch the job template again and enter the password answers.`

**Cause:** The job's password survey answers are stored encrypted with
`APP_SECRET_KEY`, and the key changed between the launch and the claim, for
example while the job waited for approval.

**Fix:** Restore the old key if that is possible. Otherwise launch the job
template again and enter the password answers; a re-launch cannot help,
because it reuses the same answers. Nothing ran on the target hosts.

## Job log: verbosity lowered

**Symptom:** The job log ends with
`[ansilume] Verbosity was lowered from 4 to 3: this runner does not support encrypted survey answers and would print them at higher verbosity. Update the runner image.`

**Cause:** The job has password survey answers and ran on a runner older
than this release, which gets them inline in the command. `ansible-playbook`
prints its whole command line from verbosity 4 on, so the server lowered the
verbosity to 3 for this job.

**Fix:** Update the runner image, see
[runners.md](runners.md#password-survey-answers). Runners that report
`secret_extra_vars` run the job at the verbosity it asked for.

## Job fails at once: the runner could not write a file

**Symptom:** A job fails before it runs, and its log says
`The runner could not create the job's private directory or files in its temp directory.`
or `The runner could not write the survey answers file.`

**Cause:** Before Ansible starts, the runner creates a new directory for
the job in its temp directory (`TMPDIR`, otherwise `/tmp`) that only its
own user can open, and writes the task records of its callback plugin, a
static inventory and the job's password answers there. The temp directory
is missing, not writable by the runner's user, read-only or full; the
second message means that only the answers file could not be written.
Nothing ran on the target hosts.

**Fix:** Fix the runner's temp directory or free space, then launch again.

## Launch refused: password answer is the placeholder

**Symptom:** A launch fails with
`The value of "db_password" is the placeholder $encrypted$; send the real value.`
The launch form shows it, `POST /api/v1/jobs` and job triggers answer `422`
with it, a workflow step fails with it as the step's error (a workflow
trigger still answers `201`), and a scheduled run fails to launch with it.

**Cause:** `$encrypted$` stands for a stored password answer in job pages,
the jobs API and schedules. It was sent back as the answer, usually because
the extra vars of an earlier job were copied into a new launch.

**Fix:** Send the real value under that key, or leave the key out when the
survey field is optional. To repeat a job with its answers, use **Re-launch**
on the job page, which reuses them encrypted.

## Launch refused: a password field needs another name

**Symptom:** Every launch of a job template fails with
`Survey field "db[pw]" has a name that cannot be used for a password answer; rename it in the survey before launching.`
The update listed the template as one with "a password field whose name
cannot carry an answer".

**Cause:** The name of a password field is empty, a number, contains `[`
or `]`, has a space at its start or end, or has a character anywhere in it
that is not printable ASCII: a control character, such as a line break or a
tab, or a character outside ASCII, such as a non-breaking space, a byte
order mark (for example from a CSV file), a zero-width space or a letter
like `ä`. An answer to such a field does not arrive under the field's
exact name (the launch form turns `db[pw]` into `db[pw`, numbers get
renumbered, a browser sends a line break as two characters, a letter
outside ASCII can be encoded in more than one way, and API callers send the
name the playbook reads, without the invisible characters), so Ansilume
could not find it to keep it secret. Answers that releases up to 2.8.1
stored under the field's exact name are encrypted by the update and show as
`$encrypted$`. The message shows the characters that
are not printable ASCII escaped as in JSON, for example `"db_password\n"`
for a line break or `"db_password\u00a0"` for a non-breaking space.

**Fix:** Rename the field in the survey editor to a variable name (letters,
digits and underscores, not starting with a digit), and use the new name in
the playbook and in the schedules of the template: in each schedule, enter
the password under the new name and remove the old key, whose stored answer
shows only as `$encrypted$`. Saving in the survey
editor removes spaces, line breaks, tabs, non-breaking spaces and byte
order marks around a name; retype a name that holds any other such
character, even when the editor shows none: the message shows where it
is. Through the API, send the name without them.

## Launch or save refused: a number too large for JSON

**Symptom:** A launch fails with
`The extra vars cannot be stored: they contain a number too large for JSON.`
or `The password answers cannot be stored: they contain a number too large for JSON.`,
shown and answered like the other launch refusals (`422` for
`POST /api/v1/jobs` and job triggers). Or saving a job template or a
schedule, in the web UI or with the API, fails with the first message, as
does `POST` or `PUT /api/v1/workflow-templates` for a step's
`extra_vars_template`, given as JSON text or as an object
(`steps[<n>]: ...`).

**Cause:** The extra vars of the launch, the template or the schedule, a
workflow step's `extra_vars_template`, or a survey answer, hold a number
JSON cannot store, such as `1e999`, which PHP reads as infinity. The second
message means the number is a password answer of a launch; saving a
schedule shows the first message for its password answers too. A job
template or schedule saved before this release can already hold such a
number: its launches are refused, its next save shows the error, whatever
else changes, and cloning such a template fails. Text that is not valid
UTF-8 in a form field, such as a survey answer, gives
`... they contain characters that are not valid UTF-8.` instead; extra
vars sent as JSON text that is not valid JSON are ignored. Before this
release, such a launch ran the job with the template's extra vars only.

**Fix:** Write the value as a string (`"1e999"`) or as a smaller number.

## Extra Vars editor: the form is not submitted, or YAML is not converted

**Symptom:** On the job template form, the schedule form or the launch
page, the form is not submitted: the Extra Vars editor gets a red border
and the focus (on the launch page, the Advanced Overrides open), and the
line under it says why. Or switching between JSON and YAML does nothing but
show a message. The messages are:

- `✗ JSON: <message of the parser>` or `✗ YAML: <message of the parser>`
- `✗ Not converted to YAML: the JSON holds a number the editor cannot convert exactly (an integer beyond ±9007199254740991, or a number too large for JSON). Edit it as JSON.`
- `✗ The YAML holds a number the editor cannot convert exactly (an integer beyond ±9007199254740991, or a number too large for JSON). Remove it, switch to JSON and enter it there.`

**Cause:** The editor submits the Extra Vars only when they parse, so that
a typo is neither saved nor launched with, and an older version is never
sent in its place. It keeps JSON as typed, but YAML mode and the switch
between JSON and YAML go through JavaScript numbers, which hold whole
numbers exactly only up to 9007199254740991 and cannot hold a number too
large for JSON (`1e999`, or YAML's `.inf` and `.nan`). Rather than change
such a number, the editor refuses it: JSON that it could not convert stays
as it is and can be saved, YAML that holds such a number cannot.

**Fix:** Correct the text the message names. For such a number, stay in
JSON mode, or remove the number from the YAML, switch to JSON and enter it
there. A number too large for JSON is refused when the form is saved or
launched, see
[Launch or save refused: a number too large for JSON](#launch-or-save-refused-a-number-too-large-for-json).
An empty editor clears the Extra Vars.

YAML mode reads YAML 1.2, while Ansible reads its own YAML files as
YAML 1.1. Plain values that the two read differently are sent with the
YAML 1.2 meaning, without a message: `0644` as the number `644` (Ansible:
octal, `420`), `yes`, `no`, `on` and `off` as text (Ansible: booleans), and
`1:30` as text (Ansible: `90`). A date such as `2026-10-11` is sent as the
text `2026-10-11T00:00:00.000Z`. Quote such values, write booleans as
`true` and `false`, or use JSON mode.

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
- **"The value of ... is the placeholder $encrypted$; send the real value."**,
  **"Survey field ... has a name that cannot be used for a password
  answer; ..."** or **"The extra vars cannot be stored: ..."** (or **"The
  password answers cannot be stored: ..."**): the job template refused the
  launch, see
  [Launch refused: password answer is the placeholder](#launch-refused-password-answer-is-the-placeholder),
  [Launch refused: a password field needs another name](#launch-refused-a-password-field-needs-another-name)
  and [Launch or save refused: a number too large for JSON](#launch-or-save-refused-a-number-too-large-for-json).
- **"The job could not be launched; see the application log."**: launching
  the job failed for another reason. The reason is in `runtime/logs/app.log`
  of the app container.
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
Give the new token to the system that calls the trigger, to send in the
`X-Trigger-Token` header.

## Trigger answers 400, 404, 405 or 422

**Symptom and cause:**

- **`400` `Missing trigger token.`**: the request carries no usable token.
  Send it in the `X-Trigger-Token` header, or as
  `Authorization: Bearer <token>`. An empty `?token=`, or `token[]=…`,
  counts as none, and only the Bearer scheme of `Authorization` counts.
- **`404`**: the token is unknown or was revoked; the `webhook.invalid_token`
  notification names its first six characters. Every other path under
  `/trigger/` answers `404 Page not found.` too, for example
  `/trigger/fire/<token>`.
- **`405`**: the request used another method than `POST`, for example a
  monitor that probes `GET /trigger/<token>`, which answered `404` in older
  versions.
- **`422`** with `{"error": "..."}`: the job template refused the launch, see
  [Launch refused: password answer is the placeholder](#launch-refused-password-answer-is-the-placeholder),
  [Launch refused: a password field needs another name](#launch-refused-a-password-field-needs-another-name)
  and [Launch or save refused: a number too large for JSON](#launch-or-save-refused-a-number-too-large-for-json).
  A workflow trigger answers `201` instead, and its run fails at the start
  step with the reason (see [Workflow step failed without a job](#workflow-step-failed-without-a-job)).

**Fix:** Call `POST /trigger/fire` (or `/trigger/fire-workflow`) with the
token in the header:

```bash
curl -X POST -H "X-Trigger-Token: <token>" -H "Content-Type: application/json" \
  -d "{}" https://ansilume.example.com/trigger/fire
```

The URL forms `?token=<token>` and, for job templates only,
`POST /trigger/<token>` still work but are deprecated: URLs end up in access
logs and proxies. A workflow token sent to `/trigger/<token>` answers `404`.

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

## Schedule fails: stored password answers cannot be decrypted

**Symptom:** A schedule starts no job when it is due. The log and the
`schedule.failed_to_launch` notification say
`Schedule #12 (Nightly deploy) failed to launch: Its stored password answers cannot be decrypted (was APP_SECRET_KEY changed?); edit the schedule and enter them again.`

**Cause:** The schedule stores its password answers encrypted with
`APP_SECRET_KEY`, and the key changed since they were saved. Every run fails
the same way; the other schedules keep running.

**Fix:** Edit the schedule and replace each `$encrypted$` in its extra vars
with the real answer, or restore the old key.

## Schedule fails: the schedule changed while its run was being launched

**Symptom:** The log and the `schedule.failed_to_launch` notification say
`Schedule #12 (Nightly deploy) failed to launch: The schedule changed while its run was being launched; it runs again at its next time.`

**Cause:** The schedule's extra vars held a value whose key had become a
password field of its job template, which the run stores encrypted before
it launches. Meanwhile someone moved the schedule to another job template,
or deleted it. The run launched nothing, as that value belongs to the old
template.

**Fix:** None needed. The schedule runs at its next due time as it was
saved, if it still exists.

## Saving a schedule fails: a password answer has no stored value

**Symptom:** Saving a schedule fails with one of these errors on its extra
vars (in the API: `422`):

- `"db_password" has no stored value; enter the real value.`
- `"db_password": the job template changed; enter the real value.`
- `The job template changed: send extra_vars with the password answers again.`
- `The stored password answers cannot be decrypted; enter the real values.`

**Cause:** In schedule extra vars, `$encrypted$` keeps the password answer
the schedule has stored under that key. There is none to keep when the
schedule never stored one, when the schedule moves to another job template
(stored answers never follow it), or when the stored answers cannot be
decrypted because `APP_SECRET_KEY` changed. A `PUT` to the API that changes
`job_template_id` without `extra_vars` cannot keep them either.

**Fix:** Replace the placeholder with the real answer, or remove the key.

## Re-launch is refused

**Symptom:** **Re-launch** on a job page shows one of these messages and
launches nothing:

- `Job #42 cannot be re-launched in status "running".`: only finished jobs
  can be re-launched. Wait for the job, or cancel it first.
- `Original template no longer exists.`: the job template was deleted.
- `The template's project or inventory changed since this job ran; launch the template and enter the password answers.`:
  the job has password answers, which are reused only while the template
  runs on the project and inventory the job ran with.
- `The original job's encrypted survey answers cannot be decrypted; launch the template and enter the password answers.`:
  `APP_SECRET_KEY` changed since the job ran.
- `Re-launch failed: ...`: the launch itself failed, with the reason.

A user who gets `403` lacks `job.launch`, which re-launch needs since this
release; `job.cancel` was enough before.

**Fix:** Launch the job template from its launch page and enter the password
answers, or give the user `job.launch`.

## Saving a job template fails: survey rules

**Symptom:** Saving a job template, in the survey editor or with
`POST`/`PUT /api/v1/job-templates`, fails with an error about its survey or
extra vars, for example:

- `Survey field name "db-host" is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore.`
- `Survey field name "env" is used more than once.`
- `Survey field "env" has an unknown type "dropdown"; use one of: text, textarea, integer, boolean, select, password.`
- `A password field cannot have a default value.`
- `Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.`

**Cause:** The survey is checked when it changes. New and renamed fields
need a variable name, names must be unique, types must be known, and
password fields get their value only at launch, never from a default. Names
are compared exactly as stored, so whitespace added to a name makes it a new
name, and a password field whose name is not printable ASCII without a
space at its start or end is refused whenever the survey changes (see
[Launch refused: a password field needs another name](#launch-refused-a-password-field-needs-another-name)).
Messages show the characters of a name that are not printable ASCII
escaped as in JSON, such as `\u00a0` for a non-breaking space. The Extra
Vars may not hold a password field's name as a key; this is checked when
the Extra Vars change or the names of the password fields do (one added,
renamed, removed, or retyped to or from password). The Extra Vars change
when their JSON value does: a key, or a value with its type (`"1"` and `1`
differ, and so do a list and an object, and a whole number beyond the
64-bit range and the same digits as text), but not spacing, key order or a
number written another way that PHP reads as the same number within the
64-bit range, such as `1.0` for `1` or `9007199254740994.0` for
`9007199254740994`. Beyond that range, a whole number written without a
fraction or an exponent is the same only as the same digits: `1e20` for
`100000000000000000000` is a change. A key written twice counts with its
last value. See
[updating.md](updating.md#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets)
for every case. A template saved before these rules keeps saving as long as
what a rule checks stays as it is, so the error appears with the first such
change.

**Fix:** Correct the field the message names, or remove the key from the
Extra Vars and enter its value at launch. For a legacy name like `db-host`
that the playbook needs, keep the field as it is: only new and renamed
fields need a variable name. A legacy name with whitespace around it, such
as `db-host` with a space in front, cannot be kept in the survey editor,
which removes the whitespace when it saves and so makes it a new name:
rename the field, or save the template through the API with the survey
unchanged.

## App container does not start after the update: APP_SECRET_KEY

**Symptom:** After an update, the app container stops right after the
migrations, and `docker compose logs app` shows
`APP_SECRET_KEY is not set or shorter than 16 characters, so the stored survey password answers cannot be encrypted. ... Nothing was changed.`

**Cause:** Job templates have password survey fields, and the update must
encrypt their stored answers, but `APP_SECRET_KEY` cannot encrypt.

**Fix:** Set `APP_SECRET_KEY` in `.env`, see
[updating.md](updating.md#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets),
then `docker compose up -d` (git checkout:
`docker compose up -d --build --force-recreate`).

## Launch fails: "The database update has not finished; run the migrations."

**Symptom:** Right after an update, a launch with password answers fails
with `The database update has not finished; run the migrations.`, and the
schedule-runner logs
`Schedules not run: 1 database migration(s) pending; they run once the update has finished.`

**Cause:** The new code runs, but the app container has not run the
migrations yet. Launches without password answers work meanwhile; scheduled
runs wait and launch once afterwards.

**Fix:** Let the app container finish (`docker compose logs -f app`). In a
git checkout the migrations run only when `app` starts, and the other
containers keep old code or old configuration files until they restart:
run `docker compose up -d --build --force-recreate` (see
[updating.md](updating.md#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets),
**Database and deployment**).

## Finished jobs show password answers again after a database restore

**Symptom:** After restoring a database backup, finished jobs show survey
password answers in plaintext on the job page and in the jobs API, and stay
so.

**Cause:** The backup was taken after the update to encrypted answers, but
before the background encryption of finished jobs had reached them. The
restored database already lists migration `m000081`, so it does not run
again, and the background encryption keeps its position and done mark in
Redis, which the restore did not touch.

**Fix:** Make it start over:
`docker compose exec app php yii maintenance/reset-survey-secret-scrub`. Its
next pass checks every finished job again, see
[updating.md](updating.md#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets).
Do not flush the cache instead: that also logs every user out and drops
queued work.

## e2e commands answer "Refused"

**Symptom:** `php yii e2e/seed` or `php yii e2e/teardown` exits with code 78
and `Refused: the e2e commands run only with YII_ENV dev or test, and YII_ENV is "prod" here.`

**Cause:** The end-to-end test commands create users with public passwords
and delete every record named `e2e-*`, so they refuse to run outside
development and test installations.

**Fix:** None for a real installation: never run them there. For the
Playwright suite, run them in a development stack with `YII_ENV=dev`.

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

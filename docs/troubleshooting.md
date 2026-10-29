# Troubleshooting

## Diagnostics script

Before digging into specific issues, run the diagnostics script. It collects system state, container health, image versions, connectivity, migration status, and recent logs — all in one go, with secrets automatically redacted.

```bash
./bin/diagnose
# or remotely, without cloning the repo:
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/diagnose | bash
```

Run it in the install directory, the one with `.env` and the compose file,
as a user who may read `.env` and reach the Docker daemon: a member of the
docker group, or with sudo. The deploy role writes `.env` for its own user
only (`0600`). Otherwise diagnose says what it lacks:

- Where it may not read `.env` or reach the daemon, it says so and how to run
  it, as `docker compose` needs both.
- Run in another directory, it says that there is no compose file there and
  names the directories Ansilume's containers were started from. In a
  subdirectory of the install directory `docker compose` still finds the
  compose file of the parent directory.

Where `docker compose` cannot tell the state of the containers, diagnose
reports it as unknown, and where it cannot tell the port of the stack either,
it skips the HTTP and port checks. Up to 2.13.1 it reported every variable of
an `.env` it could not read as missing, a running stack as not running, and
one published on another port than 8080 as unreachable.

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
docker compose exec -u www-data app php yii migrate/history

# Re-run manually
docker compose exec -u www-data app php yii migrate --interactive=0
```

A migration that fails can print its database error with the message,
which holds the SQL statement with its values: when the app container
starts, to `docker compose logs app`, and in a manual run, to your
terminal, but not to the application log. Read that output before you
share it (see the known limits in
[updating.md](updating.md#after-updating-from-290-or-older-approvals-and-workflows)).

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

## Generate Ed25519 Key Pair or Parse Inventory: the server refused the request

**Symptom:** **Generate Ed25519 Key Pair** on the credential form says
`The server refused the request (HTTP 403): your session may have ended, or you are not allowed to do this; reload the page and sign in.`,
or **Parse Inventory** (or **Refresh**) on an inventory's page shows it in
the result card; a `401` reads `HTTP 401` instead.

**Cause:** The session ended, for example after a sign-out in another tab,
or the user may not do it: generating a key pair needs
`credential.create`, also on the Edit form of a credential, which
`credential.update` opens, and parsing an inventory needs
`inventory.view` and access to the project of the inventory. Up to 2.10.1
the buttons said `Request failed: SyntaxError: …` and
`Server response was not JSON` for a session that had ended (see
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**Fixes that change behaviour**).

**Fix:** Load the page again and sign in. If the refusal stays, an admin
gives the permission, or generate the key pair elsewhere and paste its
private key into the form.

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

## Job aborted before execution: the job was not approved

**Symptom:** A job fails right after a runner claimed it, and its log says
`Job aborted before execution: inventory #3 requires an approval (approval rule #5), and this job was never approved. Launch it again (Re-launch on the job page): the new job waits for approval. The job did not run: no credentials were decrypted or sent to the runner.`,
or the same with `its job template #7` in place of `inventory #3`.

**Cause:** The job's template, the inventory the job was launched with, or
the inventory its template uses now has an approval rule, and the job was
never approved: its approval request was neither approved nor timed out
with the rule's `approve` action. Since 2.12.0 a runner starts such a job
only once it is approved. The job was queued before its job template or
inventory got its rule, put back in the queue after that
(`JOB_RECLAIM_MODE=requeue`), its job template moved to a gated inventory
after the launch, or it was queued by hand. The audit log shows
`job.started`, written when the runner claimed the job, which names the
runner and the job's credentials as they were launched, and then
`job.finished` with `claim_refusal: not_approved`, the rule
(`approval_rule_id`), what required it (`required_by`) and the job's
request with its status where it has one. No credential was decrypted or
sent to the runner, and nothing ran on the target hosts. A workflow job
step with such a job goes on as after a failed job.

**Fix:** Re-launch the job on its page, or launch its job template again:
the new job waits for approval and runs once it is approved. The queries
under **Check before the update** and **Check before you give an inventory
a rule** in
[updating.md](updating.md#after-updating-from-2111-or-older-approval-rules-for-inventories)
list the queued jobs that would meet this.

## Job aborted before execution: the inventory is missing or empty

**Symptom:** A job fails right after a runner claimed it, and its log says
one of:

- `Job aborted before execution: inventory #3, which the job was launched with, no longer exists, so its hosts are unknown. Launch the job template again. The job did not run: no credentials were decrypted or sent to the runner.`
- `Job aborted before execution: its launch record names no inventory, so its hosts are unknown. Launch the job template again. The job did not run: no credentials were decrypted or sent to the runner.`
- `Job aborted before execution: static inventory #3 "web-prod" has no content, so it names no hosts. Add its hosts, then launch the job template again. The job did not run: no credentials were decrypted or sent to the runner.`
- `Job aborted before execution: file inventory #3 "web-prod" names no source path, so its hosts are unknown. Set its source path, then launch the job template again. The job did not run: no credentials were decrypted or sent to the runner.`

**Cause:** The job has no hosts to run on: the inventory it was launched
with was deleted, its launch record names none (a job written outside a
launch), its static inventory has no content or only whitespace, or its
file inventory has no source path. Up to 2.11.1 the claim gave such a job
the inventory `localhost`, so it ran against the runner itself, or, for a
file inventory without a source path, the default inventory of Ansible or
of the repository's `ansible.cfg`. A dynamic inventory without a source
path still runs. The job's `job.finished` audit entry records
`claim_refusal` (`inventory_missing`, `inventory_not_recorded`,
`inventory_empty` or `inventory_no_source`); nothing was decrypted or sent
to the runner.

**Fix:** Give the inventory its hosts or its source path, or give the job
template another inventory, then launch the job template again. A static
inventory without content no longer runs against the runner itself: give
it hosts of its own. The inventory form and the API take new or changed
content of a static inventory only as a YAML mapping:
`all: {hosts: {localhost: }}` names the host `localhost` that the claim
gave such a job, and
`all: {hosts: {localhost: {ansible_connection: local}}}` runs on the runner
itself as the seeded selftest inventory does.

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

## Job was not canceled: it is already finished

**Symptom:** **Cancel** on a job page shows the warning
`Job #42 was not canceled: it is already succeeded.` (with the job's status
in words, such as `timed out`), and `POST /api/v1/jobs/{id}/cancel` answers
`409` with it and the status in `error.status`.

**Cause:** The job finished between loading the page, or the call, and the
cancel. A cancel changes a job only while it has not finished, so the job
keeps its outcome, and nothing else changes.

**Fix:** None.

## Job log: the runner reported after the job had finished

**Symptom:** The log of a canceled job ends with
`Runner reported completion (exit_code=0) after job was already in terminal status "canceled" — ignored.`
or `Runner reported timeout after job was already in terminal status "canceled" — ignored.`
(or another status, such as `failed`).

**Cause:** The job was canceled, or marked failed because its runner had
stopped reporting, before the runner reported its end. A runner is not told
about a cancel and runs the playbook to its end; its late report goes into
the log and changes nothing else: the job keeps its status, and no
`job.finished` audit entry, webhook, notification or workflow step follows.

**Fix:** None for the job: its status is final. A playbook that ran on after
the cancel may have changed the hosts; check them.

## A job stays running, or ends without task results: the server refused the runner's report

**Symptom:** A job stays **Running** after its playbook has ended, or a job
whose playbook exited with 0 fails with `Job did not execute against any host.`
and shows no tasks and no host summaries, or a part of its log is missing.
The runner's output says nothing about it.

**Cause:** The server refused a report of the runner with `422`, as it held
a number the server cannot store, or `""` where a number belongs, and wrote
nothing of the report: the exit code of a completion
(`POST /api/runner/v1/jobs/{id}/complete`,
`exit_code must be a whole number from -256 to 255.`), the task results
(`.../tasks`, refused as a whole:
`tasks[<key>].seq must be a whole number from -2147483648 to 2147483647.`,
the same for `duration_ms`, or `tasks[<key>] must be an object.`), or the
`sequence` of a log chunk (`.../logs`,
`sequence must be a whole number from 0 to 4294967295.`). The runner does
not send a refused report again. Runners of every version send whole
numbers in these ranges only, so such a report comes from a client of your
own or a broken one, or from a playbook that wrote to the file the task
results are read from (`ANSILUME_CALLBACK_FILE`). Up to 2.13.1 such a
number was cast: `"exit_code": 1e999` and `"exit_code": ""` ended a job as
succeeded, and a number the database cannot store answered `500`, after
the tasks before it had been saved. A `null` or missing number is 0, as
before.

**Fix:** Cancel a job that stays running: `php yii job/reclaim-stale` takes
only the jobs of runners that are offline, and its runner reports nothing
more. Launch it again once the client, or the playbook, sends whole
numbers.

## Launch refused: password answer is the placeholder

**Symptom:** A launch fails with
`The value of "db_password" is the placeholder $encrypted$; send the real value.`
The launch form shows it, `POST /api/v1/jobs` and job triggers answer `422`
with it, a workflow step fails with it as the step's error, and a scheduled
run fails to launch with it. A workflow launch with such a password answer
in its extra vars, for a password field of any job step's template, is
refused before the run exists: `/trigger/fire-workflow` and
`POST /api/v1/workflow-templates/{id}/launch` answer `422`.

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

## An API call or a form refuses a number: it must be a whole number

**Symptom:** A request that worked up to 2.13.1 is refused, and nothing is
written: `POST` or `PUT` of the API answers `422` with a field's message,
such as `Forks must be an integer.`, `Project Id must be an integer.`,
`Job Template Id must be an integer.`, `Scm Credential Id must be an integer.`,
`Required Approvals must be an integer.`, `Status is invalid.` or, for an
entry of a job template's `credential_ids`,
`credential_ids must contain positive integer credential IDs.`; a launch
answers `422` with `Verbosity must be an integer.` or
`job_template_id is required.`; the API's `POST /api/v1/teams/{id}/members`
and `/projects` answer `404` `User #0 not found.` or
`Project #0 not found.`, and `POST /api/v1/runners/{id}/move` answers
`404` `Target runner group not found.`; posted as JSON, the project,
inventory, job template, schedule and approval rule forms show the
field's error; adding a team member or a project on the team's page
answers `400` `user_id required.` or `project_id required.`; registering
or moving a runner shows `Runner group not found.` or
`Target runner group not found.`; launching a workflow answers `404`
`Workflow template #0 not found.`, and moving one of its steps answers
`404` `Workflow template #5 has no step with ID 0.` for workflow 5.

**Cause:** The value is no whole number: a fraction (`5.5`, `"5.5"`, also
one as close to a whole number as `2.9999999999999996`, which a
computation in a client can give where 3 was meant), text that is not
only digits (`"abc"`, `"5abc"`, `" 5"`, `"5e0"`), `true` or `false`, a
list or an object, also an empty one (`[]` or `{}`), or a JSON number
beyond the 64-bit int range, such as `1e30`, `9223372036854775808` or
`1e999`. Since the update to PHP 8.5 each ID and other whole number of a
request is read as the model's integer rule reads it, except the IDs of
workflow steps and of the vault password assignment, which are read as up
to 2.13.1 (see **Fix**); up to 2.13.1 it was cast with PHP's `(int)`,
which took `5.5` for 5, `"abc"` and `false` for 0 and `true` for 1, and on
PHP 8.5 a number beyond the range answered with a server error. Every
endpoint, and what it answered before, is listed in
[updating.md](updating.md#after-updating-from-2131-or-older-php-85-time-zone-data-csv-exports-whole-numbers).

**Fix:** Send the number as a JSON integer or as its digits in a string:
`5`, `"5"`, `"+5"`, `"05"` and `5.0` are all 5. A step's
`job_template_id` and `approval_rule_id` and an entry of the
`job_template_ids` of a vault password's assignment refuse `"05"` and
`5.0`, and a step's `on_success_step_id`, `on_failure_step_id`,
`on_always_step_id`, `position` and `after_step_id` refuse `"+5"`, `" 5"`
and `5.0`: send `5` or `"5"` there. Round a number your client computes
before you send it: `2.9999999999999996` is refused, `3` is taken. Send a
user's `status` as `0` (inactive) or `10` (active); `false`, `null` and
`""` no longer deactivate the user.

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

## Launch refused: too few approvers besides the launcher

**Symptom:** A launch of a job template with an approval rule, its own or
its inventory's, fails with
`Approval rule "deploy-prod" needs 1 approval(s) from users other than the one who launches, but only 0 of its approvers may decide on this job. An admin can add approvers to the rule.`
The launch form shows it after `Launch failed: `, and the launch page
already shows it in a warning above the template summary;
`POST /api/v1/jobs` and job triggers answer `422` with it, a scheduled run
fails to launch with it, and a workflow job step fails with it. A workflow
is refused the same way
before its run exists, for its first approval step or job step, in step
order, whose approval could never be granted:
`Step "Release approval": approval rule "deploy-prod" needs 1 approval(s) from users other than the one who launches, but only 0 of its approvers may decide. An admin can add approvers to the rule.`

**Cause:** Nobody approves their own launch, and only eligible approvers
count: users on the rule's approver list (for a role rule, the users
assigned that role directly; for a team rule, the members of the team) who
have an active account, are superadmins or hold `approval.decide`, and may
see the job template's project (also for the rule of its inventory; for a
workflow approval step, the workflow). Fewer of them than the rule's
required approvals remain besides the user who launches, so the request
could never be approved. That user
is whoever launches in the web UI or the
API, the user a schedule runs as (who last changed what or when it runs, or
enabled it), the user a trigger runs as (who generated its token, or the
template's creator for a token generated before Ansilume recorded that), or
the user who starts the workflow run.

**Fix:** An admin adds approvers who may decide to the rule, or lowers its
required approvals. Check that the approvers are active, hold
`approval.decide` (viewers do not) and have access to the project. Or let a
user who is none of the needed approvers launch, change or enable the
schedule (it then runs as that user), or generate the trigger token.

## Launch refused: the job template or its approval rule changed

**Symptom:** A launch fails with one of these messages, shown and answered
like the other launch refusals (`422` from `POST /api/v1/jobs` and job
triggers):

- `The approval rule of job template #7 no longer exists. An admin can choose another rule.`
- `Job template #7 was deleted.`
- `The approval rule of job template #7 changed while the job was being launched. Launch it again.`

**Cause and fix:**

- The first: the template named an approval rule that no longer existed when
  the job was launched, for example because an admin took the rule off the
  template and deleted it at that moment. The launch is refused rather than
  run without the approval. Launch again; if the message stays, an admin
  chooses a rule, or none, on the template.
- The second: the template was deleted while the job was being launched.
  Nothing was launched.
- The third: an admin changed the template's approval rule while the job was
  being launched, so the job would have waited for another rule. Launch it
  again.

Since 2.12.0 a job template whose inventory has an approval rule waits for
that rule, and a launch is refused with these too:

- `The approval rule of inventory #3, which job template #7 uses, no longer exists. An admin can choose another rule.`:
  the template has no rule of its own, and the rule of its inventory was
  gone at the launch. Launch again; if the message stays, an admin chooses
  a rule, or none, on the inventory.
- `Inventory #3 of job template #7 no longer exists. Choose another inventory for the template.`:
  the inventory of the template was gone at the launch. Choose another one
  on the template.
- `The inventory of job template #7 changed while the job was being launched. Launch it again.`:
  the template got another inventory while the job was being launched, or
  after the schedule-runner loaded it for a scheduled run. Launch it again;
  the next scheduled run takes the new inventory.
- `The approval rule of inventory #3, which job template #7 uses, changed while the job was being launched. Launch it again.`:
  an admin gave the template's inventory a rule, another one or none,
  while the job was being launched. Launch it again. When this repeats
  right after the update to 2.12.0, the containers read the database schema
  from a cache made before the migration added the rule of inventories; it
  ends when the cached schema expires, within an hour, or at once with
  `docker compose exec -u www-data app php yii cache/flush-schema db --interactive=0`,
  which clears the schema cache only. Do not run
  `php yii cache/flush cache` instead: the cache shares its Redis database
  with the sessions and the queue, so that logs every user out and drops
  queued work.

For a template and an inventory with different rules, see
[Launch refused: two approval rules meet](#launch-refused-two-approval-rules-meet).

## Launch refused: two approval rules meet

**Symptom:** A launch fails with
`Job template #7 and its inventory #3 have different approval rules, and a launch waits for one rule only. An admin can remove the template's own rule or give it the inventory's rule.`,
shown and answered like the other launch refusals; a workflow launch with
`Step "Deploy": its job template and its inventory have different approval rules, and a launch waits for one rule only. An admin can remove the template's own rule or give it the inventory's rule.`,
and the steps table marks such a job step
`job template and inventory have different approval rules · runs fail here`.
Saving a job template is refused with
`Inventory "web-prod" has the approval rule "deploy-prod": a job template on it may have only that rule as its own, or none.`,
and saving an inventory with
`Job templates that use this inventory have another approval rule of their own: "Deploy web". A launch waits for one rule only, so give them this rule or none first.`,
as an error of the rule field, `422` from the API.

**Cause:** A launch waits for one approval rule, and each job has one
approval request, so a job template on an inventory with an approval rule
has no rule of its own, or the same rule. The saves refuse two different
rules; a launch meets them only after a change made in the database
directly, and is refused before anything is written.

**Fix:** An admin removes the job template's own rule, so that its
launches wait for the inventory's, or gives it the inventory's rule. To
give the inventory the template's rule instead, give the other job
templates on the inventory that rule, or none, first. The query under
**One approval rule per launch** in
[updating.md](updating.md#after-updating-from-2111-or-older-approval-rules-for-inventories)
lists the job templates whose own rule differs from their inventory's.

## Launch refused: the limit or the verbosity

**Symptom:** A launch fails with `Limit should contain at most 255 characters.`,
`Limit must be a string.`, `Verbosity must be no greater than 5.`,
`Verbosity must be no less than 0.` or `Verbosity must be an integer.`,
and nothing is launched. The launch form shows it after `Launch failed: `;
`POST /api/v1/jobs` answers `422` with it in `error.message` and with each
such field and its message in `error.fields`, for example
`{"error": {"message": "Verbosity must be no greater than 5.", "fields": {"verbosity": "Verbosity must be no greater than 5."}}}`;
a job trigger answers `422` with `{"error": "Verbosity must be no greater than 5."}`.

**Cause:** The job record does not take the launch's `limit` or
`verbosity`: a limit longer than 255 characters, a verbosity below 0 or
above 5, a verbosity that is no whole number (a fraction such as `2.5`,
text such as `"abc"`, `true` or `false`, a number beyond the int range
such as `1e30`), or a list or an object for either, also an empty one
(`[]` or `{}`), which the launch form refuses too (a crafted
`overrides[verbosity][]=`). Before the update to approvals and workflows
such a launch failed with a server error (`500`), and a list or an object
as the verbosity was taken for a number. Up to 2.13.1 a verbosity that is
no whole number was cast: `2.5` ran at verbosity 2, `"abc"` and `false` at
0, `true` at 1, and on PHP 8.5 a number beyond the int range answered
`500`; an empty list or object launched the job with the job template's
limit and at verbosity 0, and the launch form ignored a list and launched
the job with the job template's limit and verbosity. An empty limit or
verbosity (`""`) is the job template's.

**Fix:** Send a limit of at most 255 characters as text and a verbosity
from 0 to 5 as a whole number, or leave them out to use the job
template's.

## Approve or Reject is refused, or missing

**Symptom:** The page of an approval request shows no Approve and Reject
but a note why, or a vote is refused. The web UI shows the message as a
warning, as an error page, or after `Your decision was not recorded: `;
`POST /api/v1/approvals/{id}/approve` and `/reject` answer with the status
in brackets:

- `You launched this job; another approver must decide.` (`403`): you
  launched the job; or the schedule that launched it runs as you, because
  you were the last to change what or when it runs, or to enable it; or the
  trigger that launched it runs as you, because you generated its token, or
  because you created the template and its token was generated before
  Ansilume recorded who generated it (until the token is generated again).
  Nobody approves their own launch, admins and superadmins included, and
  votes you cast before the update count for nothing (the page marks them
  `launcher, not counted`). Another approver must decide.
- `You launched this workflow run; another approver must decide.` (`403`):
  the request belongs to an approval step, or to a job step whose job
  template, or its inventory, has an approval rule, of a workflow run that
  you started, or that the workflow's trigger started as you. The run's
  page says
  `You launched this run, so another approver must decide.` Up to 2.10.1
  the refusal read as for a job.
- `You are not eligible to decide on this request.` (`403`): you are not on
  the rule's approver list, you lack `approval.decide`, or you may not see
  the job's project (the workflow, for a workflow approval step). An admin
  adds you to the rule, or another approver decides.
- `Approval request #12 is already resolved: approved.` (`409`, or
  `rejected`, `timed out` or `canceled`): someone decided it first, its
  timeout was applied, or it was canceled. The request is final.
- `Approval request #12 has expired and takes no more votes; its timeout is applied by the next maintenance run.`
  (`409`): its rule's timeout ran out. The next maintenance run, usually
  within a minute, applies the rule's timeout action (see
  [Approval request shows Timed Out or Canceled](#approval-request-shows-timed-out-or-canceled)).
- `Approval request #12 can no longer be decided: its job #34 no longer waits for approval.`
  (`409`): the job was canceled, or finished otherwise; the vote closed the
  request as canceled. Also when the job still waited but could never run:
  its workflow step no longer ran, or its run had ended, for example
  because canceling the run failed part way, or its job template had been
  deleted. Then the vote canceled the job too, so that it never runs. A job
  whose job template was removed with its project counts as one of a
  deleted template.
- `User has already voted on this request.` (`422`): you voted on this
  request already, also with a second vote sent at the same moment.
- `The decision cannot be recorded: Comment should contain at most 1,000 characters.`
  (`422`): shorten the comment.
- `The decision cannot be recorded: Comment must be a string.` (`422`): the
  API got a `comment` that is a list, an object or a number; send text, or
  leave it out.
- `Approval request #12 no longer exists.` (`404`): the request was deleted
  while the vote ran.

A vote that was recorded stays recorded when what follows it fails, such as
its audit entry, its notification or the next step of a workflow. The
application log then has
`Approval request #12: the audit entry, notification or workflow advance after the vote of user #7 failed: ...`
with the class of the error, and a workflow that did not go on can be
canceled and launched again. The same holds for a timeout, see
[Approval timeouts or the deleted-template sweep keep failing](#approval-timeouts-or-the-deleted-template-sweep-keep-failing).

## Approval request shows Timed Out or Canceled

**Symptom:** An approval request has the status Timed Out or Canceled
although nobody approved or rejected it, and its job did not run, or ran
without an approval.

**Cause:**

- Timed Out: its rule has a timeout, and nobody decided before it ran out.
  The maintenance run, which the schedule-runner starts every minute,
  applies the rule's timeout action: it rejects the job, or approves it when
  the action is `approve`, but only within 60 minutes after the request
  expired; later it rejects, and the `approval.timed_out` audit entry has
  `reason: grace_period_exceeded`. Earlier releases never applied timeouts
  on the stock deployments, so the first maintenance run after the update to
  approvals and workflows applies all overdue ones.
- Canceled: the job was canceled, or the workflow run that waited for it,
  or its job template or workflow template was deleted; or a vote or timeout
  found that its job no longer waited for approval, that the workflow step
  or run of its job had ended, or that its job template had been deleted.
  The `approval.canceled` audit entry has the reason (`operator`,
  `template_deleted`, `workflow_canceled`, `workflow_template_deleted`,
  `job_not_waiting` or `workflow_step_ended`).

**Fix:** Launch the job template or workflow again if the job should still
run. To give approvers more time, an admin raises the rule's timeout, or
sets none.

## Deleting an approval rule is refused

**Symptom:** Deleting an approval rule shows, and
`DELETE /api/v1/approval-rules/{id}` answers `409` with,
`Approval rule "deploy-prod" is in use by 2 job template(s) and 1 workflow step(s). Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.`
Since 2.12.0 inventories count first, as in
`Approval rule "deploy-prod" is in use by 1 inventory(ies), 2 job template(s) and 1 workflow step(s). Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.`
The rule page shows Delete disabled, with
`In use: remove it from the inventories, job templates and workflow steps below, and let its pending requests be decided or their jobs canceled, before deleting it.`,
and its **Used by** card lists the inventories, job templates and workflow
steps that use the rule (`error.used_by` in the API).

**Cause:** A rule is in use while inventories, live job templates, steps
of live workflows, or pending approval requests whose job still waits for
approval refer to it. Before the update to approvals and workflows,
deleting such a rule silently ungated its job templates and deleted its
requests.

**Fix:** Choose another rule, or none, on each inventory and job template:
on an inventory's edit form `— None —` under **Approval**, or
`"approval_rule_id": null` with `PUT /api/v1/inventories/{id}`, which only
admins may do; edit the workflow steps that use it to choose another rule,
or remove them (this needs `approval-rule.update`, and waits until the
workflow's runs have finished); and let its pending requests be decided,
or cancel their jobs. The card lists only what you may see and counts the
rest, such as `+ 2 inventory(ies) in projects you cannot see`: an admin
who sees everything finds the others. Then delete the rule.

A delete that the database refuses although the card lists nothing that
uses the rule is refused with
`Approval rule "deploy-prod" was not deleted: the database still refers to it. Nothing was changed.`
(`409` from the API); load the rule's page again to see what uses it now.

A delete can also end in a server error, `500` from the API, whose entry in
the application log mentions `Deadlock found when trying to get lock` or
`Lock wait timeout exceeded`: another save assigned the rule at the same
moment, twice in a row. Try again; once that save has committed, the delete
is refused with the message above.

## Approval rule form: nobody can approve

**Symptom:** The form of an approval rule warns
`Warning: none of these approvers can approve: none of them is an active user who holds approval.decide or is a superadmin, so every launch this rule gates would be refused.`

**Cause:** None of the users of the chosen role, team or user list may
decide: they are inactive, or hold neither `approval.decide` nor the
superadmin flag (viewers do not hold it). A role rule counts the users
assigned the role directly, a team rule every member of the team. Every
launch the rule gates, of a job template, of the job templates on an
inventory, or at a workflow approval step, would be refused (see
[Launch refused: too few approvers besides the launcher](#launch-refused-too-few-approvers-besides-the-launcher)).
Up to 2.11.1 the form counted team members and role holders who may not
decide, and so warned too little. Its counts are the most a launch can
find: the user who launches never approves, and only approvers who may see
the job template's project count.

**Fix:** Choose approvers who may decide, or give the users
`approval.decide`, which operators and admins hold. When fewer of them may
decide than the rule requires, the form warns
`Warning: Required approvals (3) exceeds eligible approvers (2).` instead.

## A workflow run seems stuck

**Symptom:** A workflow run shows Awaiting Approval, Paused, Queued or
Running for longer than expected, and the steps of its workflow cannot
change:
`Steps cannot change while run(s) #12 of this workflow are active: wait for them to finish or cancel them.`

**Cause and fix:** The run waits. The banner on the run's page says what
for, and who can move it on:

- `Waiting for approval at step "Release approval".` or
  `Job #34 of step "Deploy" waits for approval.`: the approval request
  below it, such as `Approval Request #18`, waits for its approvers.
  `1 of 2 approval(s)` and `2 approver(s) can still decide` say how far it
  got, and the line for you whether you may decide:
  `You can decide on this request.`, with **Review & decide**, or
  `You launched this run, so another approver must decide.` (see
  [Approve or Reject is refused, or missing](#approve-or-reject-is-refused-or-missing)).
- `Too few of the rule's approvers can still decide, so this request can no longer be approved. An admin can add approvers to the rule, or cancel the run.`:
  nobody can approve the request any more, for example because approvers
  were disabled or taken off the rule after the launch, or lost access to
  the job. An admin adds approvers to the rule, or someone cancels the run.
- `expired 2026-10-14 14:02:11; the next maintenance run applies its timeout (then rejected)`:
  the rule's timeout has passed, and the next maintenance run, within a
  minute, rejects or approves the request as the rule says. When the
  banner keeps saying so, the maintenance run fails (see
  [Approval timeouts or the deleted-template sweep keep failing](#approval-timeouts-or-the-deleted-template-sweep-keep-failing)).
  A request that `never expires` waits until someone decides, or the run
  is canceled.
- `Its approval request no longer exists: the next maintenance run cancels the wait, and the step fails.`:
  the job waits for approval without a request, which releases up to 2.9.0
  could leave; the next maintenance run cancels the job, and the run goes
  on as after a rejection (see
  [A job was canceled without anyone canceling it](#a-job-was-canceled-without-anyone-canceling-it)).
- `Paused at step "Wait" until someone resumes the run.`: **Resume** on the
  run's page or the dashboard moves it on; it needs `workflow.launch` and
  operator access to the project of every job step.
- `Job #34 of step "Deploy" is queued: it waits for a runner.`: no runner
  has claimed the job. Check that the job template's runner group has an
  online runner (see [runners.md](runners.md)), and the job's page.
- No banner, and the step reads Running: its job runs; its page shows the
  log.

**Cancel** on the run's page ends the run, whatever it waits for, with its
job and approval request. The API says the same:
`GET /api/v1/workflow-jobs/{id}` has `waiting_for` and `current_step`, and
the current step's `approval` has `open_count` and
`can_still_be_approved`; `GET /api/v1/workflow-jobs?waiting_for=approval`
lists the runs that wait for an approval, and
`GET /api/v1/approvals?status=pending` the requests (see
[workflows.md](workflows.md#runs-and-approvals)).

## Workflow step failed without a job

**Symptom:** A workflow run fails at a step whose job did not run. The run
page shows a message under the step's status, and the API returns it as
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
- **"The approval rule of this step no longer exists."**: the approval step
  has no rule. An approval rule that steps use can no longer be deleted, so
  this happens only to steps whose rule was deleted before the update to
  approvals and workflows (migration `m000083` cleared their rule), and to
  approval steps an older version saved without a rule. Such a workflow is
  also refused at launch (see [Workflow launch refused](#workflow-launch-refused)).
- **"Not run: approval rule "deploy-prod" needs 1 approval(s) from users
  other than user #7, who launched this workflow, but only 0 of its
  approvers may decide."**: the run reached an approval step whose request
  could never be approved, as too few eligible approvers remain besides the
  user who started the run, for example because an approver was disabled
  after the launch. No request was created. See
  [Launch refused: too few approvers besides the launcher](#launch-refused-too-few-approvers-besides-the-launcher).
- **"The approval request could not be created; see the application log."**:
  writing the approval request of an approval step failed. The application
  log (`runtime/logs/app.log` of the app container, or of the
  schedule-runner when an approval timeout started the step) has
  `Workflow job #12: the approval request of step #31 failed: ...` with
  the class of the error, its SQLSTATE and the driver's error code.
- **"The value of ... is the placeholder $encrypted$; send the real value."**,
  **"Survey field ... has a name that cannot be used for a password
  answer; ..."** or **"The extra vars cannot be stored: ..."** (or **"The
  password answers cannot be stored: ..."**): the job template refused the
  launch, see
  [Launch refused: password answer is the placeholder](#launch-refused-password-answer-is-the-placeholder),
  [Launch refused: a password field needs another name](#launch-refused-a-password-field-needs-another-name)
  and [Launch or save refused: a number too large for JSON](#launch-or-save-refused-a-number-too-large-for-json).
- **"The job could not be launched; see the application log."**: launching
  the job failed for another reason, also a database error. The
  application log (as above) has
  `Workflow job #12: launching job template #15 for step #31 failed: ...`
  with the class of the error, its SQLSTATE and the driver's error code.
  Before, a database error there left the run running for good, which
  locked the steps of its workflow.
- **"Not run: unknown step type "…"."**: the step's type is none of job,
  approval and pause, compared exactly (`JOB` or `job ` with a trailing space
  are unknown too). Older versions stored such steps: the API did not
  validate steps, and the add-step form stored `true` from a JSON request as
  `1`.

A step can also end the run because of its approval, or because the run
cannot go on after it. Its error says why:

- **"Approval request #12 was rejected."**, **"Approval request #12 timed
  out."** or **"Approval request #12 was canceled."** on an approval step,
  and **"Not run: approval request #12 was rejected."**, **"... timed
  out."** or **"... was canceled."** on a job step whose job waited for
  approval: the gate did not open. The request was rejected, timed out with
  the rule's timeout action `reject` (or more than 60 minutes late), or was
  canceled with its job or run. An empty On Failure branch ends the run as
  failed then, and an Always branch is not taken, on approval steps and on
  job steps alike; before the update to approvals and workflows, the run
  went on with the next step, or with the Always branch.
- **"Not approved: the approval request of this step no longer exists."**
  on an approval step, and **"Not run: the approval request of its job no
  longer exists."** on a job step: the step waited for approval without an
  approval request, which releases up to 2.9.0 left when a rule was deleted
  while it had pending requests, and the maintenance run canceled its job
  (see [A job was canceled without anyone canceling it](#a-job-was-canceled-without-anyone-canceling-it)).
  The run goes on as after a rejection.
- **"The step this branch leads to no longer exists (step ID 7)."**, added
  to the step's own error, also on a step that succeeded: one of its
  branches leads to a step that was removed, so the run fails instead of
  ending as if the workflow were done. Steps that ran before 2.11.0 show
  **"The next step #7 of this workflow no longer exists."** The steps table
  of the workflow's page labels such a branch
  `missing step (ID 7) · run fails`.
- **"This step was removed from the workflow while it ran, so the run
  cannot go on."**: the step's row was deleted while it ran, which Ansilume
  itself refuses while a run is active, for example in the database; the
  run fails.
- **"Not finished: the run was canceled."** and **"Not finished: the run was
  canceled because its workflow template was deleted."**: the run was
  canceled, or its workflow template deleted, while the step ran, or as it
  started. Its job was canceled with its pending approval request; when
  that failed, the maintenance run cancels the job later (see
  [A job was canceled without anyone canceling it](#a-job-was-canceled-without-anyone-canceling-it)).
  The run's page shows such a step as Canceled; the API keeps its status
  `failed`.
- **"Not finished: the run had ended."**: the step still showed as running
  in a run that had ended otherwise; the maintenance run ended it.

**Fix:** Give the user back what they lack (re-enable them, restore
`workflow.launch`, or operator access to the step's project), or let a user
who may operate every job step launch the workflow again. For a trigger,
revoke its token and generate a new one as such a user. For a missing job
template or approval rule, or a branch to a removed step, edit the step and
choose another job template, rule or branch (see
[Editing a step](workflows.md#editing-a-step)), after the workflow's runs
finished or were canceled (see
[Changing workflow steps is refused](#changing-workflow-steps-is-refused));
only admins change the steps of a workflow with an approval step or a job
step whose job template has an approval rule. A step's type cannot change:
for an unknown step type, add a step of the right type, edit the steps that
lead to the old one to lead to the new one, and remove the old one. For a
closed gate, set the step's On Failure branch to the step that should follow
a rejection, if the workflow should go on; for too few approvers, an admin
adds approvers to the rule. Then launch the workflow again.

## Workflow launch refused

**Symptom:** Launching a workflow shows `Launch failed: ` and the reason,
`POST /api/v1/workflow-templates/{id}/launch` answers `422` with it, and
`/trigger/fire-workflow` answers `422` with `{"error": "..."}`. No run is
created.

**Cause and fix:**

- `The extra vars must be a JSON object, such as {"env": "prod"}.`: the
  launch extra vars are a list, a number, a text that is no JSON object,
  `true` or `false`. Send an object, or leave them out: `null`, an empty
  text and `{}` mean none.
- `The extra vars cannot be stored: they contain a number too large for JSON.`,
  `... characters that are not valid UTF-8.` or
  `The extra vars cannot be stored as JSON.` (and the same with
  `The password answers cannot be stored: `): see
  [Launch or save refused: a number too large for JSON](#launch-or-save-refused-a-number-too-large-for-json).
- `The extra vars are too large: a run stores at most 65535 bytes of them as JSON.`:
  send fewer or shorter extra vars.
- `The value of "db_password" is the placeholder $encrypted$; send the real value.`:
  `db_password` is a password field of the job template of one of the job
  steps; see
  [Launch refused: password answer is the placeholder](#launch-refused-password-answer-is-the-placeholder).
- `Workflow template #5 has no steps.`: add steps first. A workflow trigger
  answered `500` `Launch failed.` for it before.
- `Workflow template #5 was deleted.`: the workflow was deleted while it was
  being launched.
- `Step "Release approval": approval rule "deploy-prod" needs 1 approval(s) from users other than the one who launches, but only 0 of its approvers may decide. An admin can add approvers to the rule.`:
  see [Launch refused: too few approvers besides the launcher](#launch-refused-too-few-approvers-besides-the-launcher).
- `Step "Release approval": its approval rule no longer exists. An admin can choose another rule.`:
  the approval step has no rule (see
  [Workflow step failed without a job](#workflow-step-failed-without-a-job)).
  An admin edits the step and chooses a rule. For a job step it means
  that the rule of its job template, or of the template's inventory, is
  gone: an admin chooses another rule, or none, there.
- `Step "Deploy": its job template and its inventory have different approval rules, and a launch waits for one rule only. An admin can remove the template's own rule or give it the inventory's rule.`:
  see [Launch refused: two approval rules meet](#launch-refused-two-approval-rules-meet).

A refusal by team scoping answers `403` instead, as before.

A database error is no refusal. The page shows `Launch failed: ` with the
error's class, SQLSTATE and driver error code when the error escaped Yii's
wrapping, such as
`Launch failed: PDOException (SQLSTATE HY000, driver error 2006)`, and the
error page otherwise; `POST /api/v1/workflow-templates/{id}/launch`
answers `500` with the error's class and codes, such as
`yii\db\Exception (SQLSTATE 40001, driver error 1213)`, and the application
log has
`Launch of workflow template #5 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213)`.
No run was created, unless the error struck while the first step started:
then the run has ended as failed. Launch again; for an error that repeats,
see
[A page or an API call fails with a server error](#a-page-or-an-api-call-fails-with-a-server-error).

## Workflow steps run in the wrong order

**Symptom:** A workflow run starts with another step than intended, runs
its steps in another order than the list on the workflow's page, or runs a
job step before the approval step that should come first. Build, Test and
Deploy, for example, run as Deploy, Test, Build.

**Cause:** A run starts with step 1 of the steps table on the workflow's
page, marked `Start`, and follows the branches of each step. A branch that
names a step goes there; an empty branch goes on with the next step in the
list, marked `(next)`, such as `→ 2. Test (next)`. So the order of the list
decides where empty branches lead, and adding, moving or removing a step
changes it. Read the branches of each step:

- `→ 3. Deploy (next), also after a failure` on a job step's On Failure: the
  run goes on with the next step also when the job failed, as job steps
  added through the API, or before 2.11.0, do by default.
- `↺ 1. Build`: the branch leads back to the same or an earlier step, and the
  run repeats steps until a branch leads elsewhere.
- `end · run succeeds` or `end · run fails`: the run ends there.

Steps that share a step order run in the order of their IDs, as the list
shows them; before 2.11.0, an empty branch skipped such a step. The query
under **Steps that share a step order** in
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs)
lists them. From 2.3.15 to 2.10.0, a step added with the Step Order that
the Add Step form filled in went to the top, not to the end (see
[updating.md](updating.md#after-updating-from-2100-or-older-new-workflow-steps-go-to-the-end)).

**Fix:** On the workflow's page, check the success path above the steps
table, then move the steps with ▲ / ▼ or **Move to…** until the table lists
them in the order they should run, and edit the steps whose branches should
lead elsewhere (see [workflows.md](workflows.md#moving-steps)). To end the
run after a failed job, choose `End the workflow: the run fails` under On
Failure. When a move or an edit is refused, see
[Changing workflow steps is refused](#changing-workflow-steps-is-refused).
The query under **Check your workflows** in
[updating.md](updating.md#after-updating-from-2100-or-older-new-workflow-steps-go-to-the-end)
lists the job steps that come before the first approval step of their
workflow.

## Changing workflow steps is refused

**Symptom:** Adding, editing, moving or removing a step on a workflow's
page, a call of the step endpoints (`/api/v1/workflow-templates/{id}/steps`),
or `POST` or `PUT /api/v1/workflow-templates` with `steps`, is refused. The
page shows the message after `Step not added: `, `Step not saved: ` or
`Step not moved: `, alone for a step that was not removed, or on an error
page (`403`, `404`); the API answers with the status in brackets:

- `Steps cannot change while run(s) #12 of this workflow are active: wait for them to finish or cancel them.`
  (`409`, with every such run in `error.workflow_job_ids`): a run reads the
  steps at every step it reaches, so the steps stay as they are while a run
  of the workflow is running. The workflow's page names the runs and hides
  the step controls, and the page of a step disables its form. Wait for
  them, or cancel them; a run that waits for an approval, a resume or a
  runner says so on its page (see
  [A workflow run seems stuck](#a-workflow-run-seems-stuck)). A run that
  never finishes, for example one whose step records an older release
  deleted, blocks the steps until it is canceled; the eighth query under
  [updating.md](updating.md#after-updating-from-290-or-older-approvals-and-workflows)
  lists such runs.
- `Step "Build" was not removed: other steps route to it (step 3 "Retry"). Edit those steps to lead elsewhere first, then remove it.`
  (`409`, with those steps in `error.step_ids`): a branch of another step
  leads to this one, and would fail the run. Edit the steps it names to
  lead to another step or to the end, then remove it; the page of the step
  lists them under `Steps that route here`, with a `Not used: …` note under
  a step whose branch here no run takes, which keeps the step all the same.
  Up to 2.10.1 this read
  `Step "Build" was not removed: other steps route to it (#10 "Retry"). Remove those steps first, and add them again with other routes if you still need them.`
- `Only admins may add approval steps: that needs approval-rule.update.`
  (`403`): adding an approval step, or steps that include one, needs
  `approval-rule.update`.
- `This workflow has an approval step: only admins may change its steps (approval-rule.update).`
  (`403`): every change of the steps of a workflow with an approval step
  needs `approval-rule.update`, editing a step included, also a `steps`
  list with the same steps. Users without it may still rename the
  workflow, launch it and manage its trigger token.
- `Only admins may add a job step whose job template has an approval rule: that needs approval-rule.update.`
  (`403`): a gated job step, one whose job template has an approval rule, is
  for admins, as an approval step is: its job runs as the user who starts
  the workflow, who never approves it, so whoever chose the step could
  approve it alone. This holds for editing a job step to use such a job
  template too. The step forms offer users without `approval-rule.update`
  only job templates without an approval rule. It also comes when an admin
  gives that job template an approval rule at the same moment: Add Step,
  Edit Step, the step endpoints and a `steps` list of `POST` or
  `PUT /api/v1/workflow-templates` check it again while they write the
  change, and write nothing.
- `This workflow has a job step whose job template has an approval rule: only admins may change its steps (approval-rule.update).`
  (`403`): the workflow has a gated job step, so every change of its steps
  needs `approval-rule.update`, as for an approval step. This holds as well
  when the job template got its approval rule after the step was added.
  Ask an admin to make the change. Such a step stays as it was added: its
  job runs as the user who starts the run, and the user who added it may
  approve it, so an admin reviews it, and removes it if it should not be
  there (see
  [updating.md](updating.md#after-updating-from-290-or-older-approvals-and-workflows),
  **Only admins change what a gated job template runs**).
- `Only admins may add a job step whose job template's inventory has an approval rule: that needs approval-rule.update.`
  and
  `This workflow has a job step whose job template's inventory has an approval rule: only admins may change its steps (approval-rule.update).`
  (`403`): the same, for a job template whose inventory has an approval
  rule, also one it got after the step was added. Since 2.12.0 the step
  forms offer users without `approval-rule.update` neither kind of gated
  job template (see
  [updating.md](updating.md#after-updating-from-2111-or-older-approval-rules-for-inventories),
  **Gated job steps**).
- `You may not use this job template in a workflow: that needs operator access to its project.`
  (`403`, with the job template in `error.job_template_ids`): you may see
  the job template, but not operate its project; choose one you may
  operate. `Step not added: A job step needs a job template.` and
  `Step not added: The selected job template does not exist.` (`422`) ask
  for a job template you may use.
- `A step's type cannot change: add a new step and remove this one.`
  (`422`): add a step of the other type where this one stands, edit the
  steps that lead to this one to lead to the new one, then remove it.
- `Step not added: The step to place it after (step ID 43) is not part of this workflow. Load the workflow again and choose another position.`
  (`422`): someone removed that step after the page was loaded. Load the
  page again and choose another position. A position that does not fit
  answers `A step cannot be placed after itself.` or, from the API,
  `position must be "start", "end" or a number from 1 to 5.` (`422`); a
  position the web form does not offer,
  `Choose a position: at the start, at the end, or after a step of this workflow.`
- The step endpoints refuse a body they cannot read (`422`):
  `Send either position or after_step_id, not both.`,
  `Send exactly one of position, after_step_id and direction.`,
  `position must be "start", "end" or a whole number from 1.`,
  `after_step_id must be the ID of a step of this workflow.`,
  `direction must be "up" or "down".`,
  `on_failure_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.`,
  or, for a pause step,
  `A pause step only has on_success_step_id: Resume continues there. Send on_failure_step_id and on_always_step_id as null.`
- `Workflow template #7 has no step with ID 44.` (`404`): the step was
  removed meanwhile, or belongs to another workflow.
- `Step not added: An approval step needs an approval rule.`, or
  `Step not added: The selected approval rule does not exist.`: choose an
  existing rule for the approval step. A `steps` list answers
  `steps[1]: An approval step needs approval_rule_id, the ID of an approval rule.`
  or `Approval rule(s) not found: #9.` (`422`).
- `Step not added: An approval step cannot have an Always branch: a rejection would continue past it.`:
  approval steps have no Always branch; use On Success and On Failure. The
  step endpoints answer it (`422`) for an approval step that keeps an
  Always branch an older version saved, until the change sends
  `on_always_step_id: null`; the page of the step shows such a branch
  under On Success and saves it there.
- `Step not added: The selected step is not part of this workflow.`: a
  branch names a step of another workflow, or one that was removed
  meanwhile; choose a step of this workflow.
- `extra_vars_template must be null or a JSON object that maps extra var names to the names of output variables, such as {"app_version": "build_version"}.`
  (`422`; `steps[<n>]: ` before it in a `steps` list): the variable mapping
  of a step is a list, a number, text, `true` or `false`, holds a value that
  is not text, or has an empty key or a key that is a whole number, such as
  `"0"`. Send an object of extra var name to output variable name, or
  `null` to remove the mapping. A mapping of another shape that an older
  version stored stays while a change leaves it as it is.

## Deleting a workflow template: runs could not be canceled

**Symptom:** Deleting a workflow template warns
`Workflow template "Release web" deleted. 1 running run(s) were canceled; 1 could not be canceled, the maintenance run retries them (see the application log).`,
and `DELETE /api/v1/workflow-templates/{id}` answers with `failed_runs`
above 0.

**Cause:** The template is deleted, and its running runs are canceled one
after the other. Canceling one of them failed, for example on a database
error; the others were canceled all the same. The application log has
`Canceling run #12 of deleted workflow template #5 failed: ...` with the
class of the error, its SQLSTATE and the driver's error code (see
[Approval timeouts or the deleted-template sweep keep failing](#approval-timeouts-or-the-deleted-template-sweep-keep-failing)).
Such a run can still show as running, or show as canceled while the job of
one of its steps still waits: no runner starts that job, and its approval
request takes no more votes.

**Fix:** None needed: every ten minutes the maintenance run cancels the
running runs of deleted workflow templates, and the jobs that ended runs
left waiting. To cancel a run that still shows as running at once, use
**Cancel** on its page; a waiting job of a run that shows as canceled can
be canceled on the job's page.

## Canceling or resuming a workflow run fails with a database error

**Symptom:** **Cancel** or **Resume** on the page of a workflow run shows
`Canceling workflow run #12 failed with a database error; see the application log. Load the run again: a run that still runs can be canceled again; for a canceled run, the maintenance run ends its steps and cancels their waiting jobs.`
or
`Resuming workflow run #12 failed with a database error; see the application log. Load the run again: a pause step that still waits can be resumed again.`,
and `POST /api/v1/workflow-jobs/{id}/cancel` or `/resume` answers `500`
with it. Before the update to approvals and workflows, such an error
answered a bare server error.

**Cause:** A database error, such as a deadlock, a lock wait timeout or a
lost connection. The application log of the app container
(`runtime/logs/app.log`) has, for example,
`Workflow run #12: the cancel failed with a database error: yii\db\Exception (SQLSTATE 40001, driver error 1213).`
(`the resume` for a resume): the class of the error, its SQLSTATE and the
driver's error code, never the statement. What the error left depends on
where it struck. A cancel that failed before it claimed the run left the
run running; one that failed after left it canceled, with steps that can
still show as running and jobs that can still wait. A resume that failed
before it moved the pause step on left the step waiting; one that failed
later left the run where it stopped.

**Fix:** Load the run again, on its page or with
`GET /api/v1/workflow-jobs/{id}`. Cancel a run that still runs again, or
resume a pause step that still waits again. For a run that shows canceled,
nothing: no runner starts its waiting jobs, and the maintenance run ends
its steps and cancels the jobs (see
[A job was canceled without anyone canceling it](#a-job-was-canceled-without-anyone-canceling-it)).
A run that stopped after a resume can be canceled.

## A notification was not sent

**Symptom:** A notification does not arrive, for example the email or the
webhook call of a failed job.

**Cause:** Every send of a notification template writes an audit entry:
`notification.dispatched`, or `notification.failed` with the `error` of the
channel, such as the HTTP status a webhook endpoint answered, or that the
mailer could not send an email. The application log of the container that
sent it (the app container for the web UI, the API and runner reports, the
schedule-runner for schedules and the maintenance run, the queue-worker for
project syncs) has the same error, for example
`NotificationDispatcher: failed to send notification #3 (webhook) for job.failed: ...`.
A database error is written to both only as its class, SQLSTATE and driver
error code, for example
`yii\db\Exception (SQLSTATE HY000, driver error 2006)`: its message
carries the SQL statement with its values, which the `notification.failed`
entry and the log line held before the update to approvals and workflows.
When the notification templates of a workflow event cannot even be read,
no audit entry is written; the log has
`Workflow job #12: the workflow.step_failed notification failed: yii\db\Exception (SQLSTATE HY000, driver error 2006).`,
and the run goes on.

**Fix:** For a channel error, check the channel's settings on the
notification template (for email also the
[mail settings](installation.md#email--notifications) of the installation),
then use **Send Test** on its page. When the channel does not take the test
message, the page shows `Test notification failed: ` and the channel's
error as an error, for example `WebhookChannel: HTTP 404 — ...` with the
start of the endpoint's answer, or `WebhookChannel: HTTP 0 — ` when nothing
answered; `POST /api/v1/notification-templates/{id}/test` answers `502` with
`Test failed: ` and the same error. Before the update to approvals and
workflows the test said `Test notification sent successfully.` whatever the
channel answered. Like every send, the test writes its
`notification.dispatched` or `notification.failed` audit entry. For a
database error, see
[Approval timeouts or the deleted-template sweep keep failing](#approval-timeouts-or-the-deleted-template-sweep-keep-failing)
for what the SQLSTATE and the driver's error code mean. A notification that
failed is not sent again.

## Saving a notification template or a webhook: a secret has no stored value

**Symptom:** Saving a notification template, on its edit form or with
`POST` or `PUT /api/v1/notification-templates`, is refused (`422` in the
API) with one of these messages, where the first part names the place of
the secret in the channel's config:

- `bot_token has no stored value; enter the real value.`
- `webhook_url: the channel changed; enter the real value.`
- `headers.Authorization: url changed; enter the real value.`

Or saving an outbound webhook, on its form or with `POST` or
`PUT /api/v1/webhooks`, says
`The secret has no stored value; enter the real value.`

**Cause:** Pages and the API show every channel secret of a notification
template as `$encrypted$`, and the edit form of an outbound webhook shows
its signing secret so (the API never answers it). A save that sends
`$encrypted$` back keeps the stored value. That works
only where a value is stored: not for a new template or webhook, not after
the template's channel changed, and not for a header of a webhook channel
once its URL changed, so that a header is never sent to another address
without being entered again.

**Fix:** Enter the real value in place of `$encrypted$`: the bot token, the
webhook URL, the routing key or the header's value; for an outbound
webhook, the signing secret, or an empty field to send deliveries
unsigned.

## Approval timeouts or the deleted-template sweep keep failing

**Symptom:** Approval requests stay **Pending** after they expired, or
waiting jobs and running runs of deleted templates, or jobs that canceled
workflow runs left waiting, are not canceled. `docker compose logs
schedule-runner` does not say why: the maintenance run prints only the
tasks that did something, and counts the cancels that failed (`failed_runs`,
`stranded_jobs_failed`) without a reason. The application log has lines
such as:

- `Approval timeout of request #12 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213).`
- `Approval request #12 was settled by its timeout, but the audit entry, notification or workflow advance after it failed: yii\db\Exception (SQLSTATE HY000, driver error 2006).`
- `MaintenanceService: applying approval timeouts stopped after an error (yii\db\Exception); the next run retries.`
- `MaintenanceService: the sweep of deleted job and workflow templates stopped after an error (yii\db\Exception); the next run retries.`
- `Canceling job #42 of deleted job template #15 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213); the maintenance run retries while the job waits.`
- `Canceling run #12 of deleted workflow template #5 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213); the maintenance run cancels the run, or the jobs it left.`
- `Run #12 was canceled, but ending its step #31 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213); no runner takes the step's job, and the maintenance run ends the step and cancels the job.`
- `Canceling stranded job #42 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213); the next maintenance run retries.`
- `Ending step #31 of ended run #12 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213); the next maintenance run retries.`

The lines of the maintenance run are in the application log of the
schedule-runner, those of a delete or a cancel in the web UI or the API in
the app container's. In a git checkout both write `runtime/logs/app.log`.
With the prebuilt images, the log is inside each container:

```bash
docker compose exec schedule-runner grep -e 'Approval timeout of request' -e 'settled by its timeout' -e 'MaintenanceService:' -e 'Canceling ' -e 'was canceled, but' -e 'Ending step' /var/www/runtime/logs/app.log
docker compose exec app grep -e 'Canceling ' -e 'was canceled, but' /var/www/runtime/logs/app.log
```

**Cause:** A database error. The lines name its class, its SQLSTATE and the
driver's error code, never the statement, which can carry values: SQLSTATE
`40001` with `1213` is a deadlock and `1205` a lock wait timeout, which
usually pass; `HY000` with `2006` is a lost connection to the database, and
so is `4031`, which PHP 8.4 and later report when MySQL 8.0.24 or later
closed a connection that was idle longer than its `wait_timeout`.

- A timeout that failed is undone and tried again at the next run, a minute
  later, before the other expired requests. A request whose timeout keeps
  failing stays first and keeps its job waiting.
- A timeout that was applied, but whose audit entry, notification or next
  workflow step failed afterwards, is not tried again: the request is
  settled, and a workflow run that waited for it stays at its step.
- When a whole task stopped (`MaintenanceService: ...`), the approval
  timeouts run again a minute later, and the sweep at its next turn, ten
  minutes later.
- A job or run whose cancel failed keeps waiting or running, and the next
  run tries again, a minute later. No runner starts a job of a deleted job
  template or of a workflow run that has ended meanwhile, and an approval
  of such a job is refused: the vote, or the timeout, closes its request
  and cancels the job instead. `DELETE /api/v1/job-templates/{id}` does
  not count the jobs whose cancel failed, and
  `DELETE /api/v1/workflow-templates/{id}` counts the runs as
  `failed_runs`.

**Fix:** For a deadlock or a lock wait timeout, nothing: the next run tries
again. For an error that repeats, look into the database with the SQLSTATE
and the driver's error code (its own log, free space, the connection). A
request whose timeout keeps failing ends when its job is canceled on the
job's page: the request is closed as canceled. A workflow run that stays at
its step because what followed its timeout failed does not go on by
itself: cancel it and launch it again.

## A job was canceled without anyone canceling it

**Symptom:** A job shows **Canceled**, and its `job.canceled` audit entry
has no user and the reason `template_deleted`, `workflow_canceled`,
`workflow_template_deleted` or `approval_request_missing`.

**Cause:** The maintenance run cancels jobs that wait for something that can
never come: the waiting jobs of a deleted job template
(`template_deleted`); the waiting jobs whose job template is gone, five
minutes after they were created, as releases up to 2.9.0 removed the
deleted job templates of a deleted project with their jobs still waiting
(`template_deleted`; the job shows no job template); the jobs a workflow
run left waiting when canceling the run, or deleting its workflow template,
could not cancel them (`workflow_canceled`, `workflow_template_deleted`);
and jobs that have waited for approval for five minutes or more without an
approval request, which releases up to 2.9.0 left when a rule was deleted
while it had pending requests (`approval_request_missing`). A workflow step
that waited for such a job fails with it (see
[Workflow step failed without a job](#workflow-step-failed-without-a-job)).
Deleting a project now cancels the waiting jobs of its deleted job
templates itself, with the user who deleted it, before it removes them.

A vote or a timeout on the approval request of such a job cancels it as
well, with the same reason, and names the voter as the user of the
`job.canceled` entry (nobody for a timeout).

**Fix:** None: the job could not run. Launch its job template again if it
should still run.

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
  [Launch refused: a password field needs another name](#launch-refused-a-password-field-needs-another-name),
  [Launch or save refused: a number too large for JSON](#launch-or-save-refused-a-number-too-large-for-json)
  and [Launch refused: too few approvers besides the launcher](#launch-refused-too-few-approvers-besides-the-launcher).
  A workflow trigger answers `422` for the reasons under
  [Workflow launch refused](#workflow-launch-refused), and creates no run;
  a job step that is refused later, when the run reaches it, fails with
  the reason (see [Workflow step failed without a job](#workflow-step-failed-without-a-job)).

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

**Cause:** A schedule launches its job template as the user it runs as: the
user who last changed its job template, cron expression, timezone or extra
vars, or enabled it; its creator until then. At every due time Ansilume
checks that this user is active, holds `job.launch` and may operate the
template's project, with or without teams. A refused schedule stays enabled
and moves on to its next due time, where the check runs again.

**Fix:** Re-enable the user, or give them back `job.launch` or operator access
to the project. Otherwise let a user who may launch the template disable and
enable the schedule, or change what or when it runs: the schedule then runs
as that user. Renaming it, or saving it without a change, keeps the user it
runs as. The schedule page shows that user under **Runs as**, and the API
returns it as `created_by` of `GET /api/v1/schedules/{id}`.

## Schedule cannot be enabled: its job template was deleted

**Symptom:** **Enable** on a schedule shows
`Schedule "Nightly deploy" was not enabled: its job template #15 was deleted. Edit the schedule to choose another job template, or delete it.`,
and `POST /api/v1/schedules/{id}/toggle` answers `422` with it. Or a
schedule was disabled without anyone disabling it, and the
`schedule.failed_to_launch` notification said
`Job template #15 was deleted; the schedule was disabled.`

**Cause:** A deleted job template can never run again. Deleting a template
disables its schedules, a due schedule of a deleted template disables
itself, and the maintenance run disables the schedules of templates deleted
before the update to approvals and workflows. The `schedule.toggled` audit
entry has `reason: template_deleted`, and no user when the system disabled
the schedule. Before, such a schedule stayed enabled and failed at every due
time with `Job template not found for schedule #12`.

**Fix:** Edit the schedule, choose another job template and enable it, or
delete the schedule. Saving it while it still points at the deleted template
fails with `The selected job template does not exist.`

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

## Saving a schedule is refused: the cron expression

**Symptom:** Saving a schedule fails with an error on its cron expression
(in the API: `422` on `cron_expression`), such as:

- `Invalid cron expression: the range 22-2 of the hour field runs backwards. Write the smaller value first; ranges do not wrap around, so split one that would, as in 0-2,22-23.`
- `Invalid cron expression: the range 50-10/5 of the minute field runs backwards. Write the smaller value first; ranges do not wrap around, so split one that would, as in 0-10/5,50-59/5.`
- `Invalid cron expression: the range 50-5 of the minute field runs backwards. Write the smaller value first; ranges do not wrap around, so split one that would.`
- `Invalid cron expression: the scheduler would skip some values of the list 18,6 of the hour field. Write its values in ascending order, as in 6,18.`
- `Invalid cron expression: the scheduler would skip some values of the list 10-50,5 of the minute field. Write its values in ascending order, as in 05,10-50.`
- `Invalid cron expression: the scheduler would skip some values of the list */2,*/3 of the minute field, and Ansilume finds no list of these values in an order it walks that fits beside the other fields into the 64 characters a cron expression may have. …`
- `Invalid cron expression: the list *,5 of the minute field holds *, …`
- `Invalid cron expression: the range SUN-SAT of the day-of-week field starts with Sunday as 7. Write Sunday as 0 at the start of a range, as in 0-SAT.`
- `Invalid cron expression: the range 7-7 of the day-of-week field is Sunday alone, …`
- `Invalid cron expression: the range SAT-SUN of the day-of-week field ends with SUN, so the scheduler never runs it on a Sunday. Write Sunday as 7 at the end of a range, as in SAT-7.`
- `Invalid cron expression: the range 5-0 of the day-of-week field ends with Sunday as 0, a smaller value than its start. Write Sunday as 7 at the end of a range, as in 5-7.`
- `Invalid cron expression: 0-7/2 of the day-of-week field steps from 0 to 7, so the scheduler never runs it on a Sunday. Write it as 0-6/2.`
- `Invalid cron expression: the step /90 of the minute field is too large. Use a step smaller than 59, or list the values.`
- `Invalid cron expression: MON-FRI/2 of the day-of-week field has a step after names. Write the range with numbers, as in 1-5/2.`
- `Invalid cron expression: 31W of the day-of-month field needs a day from 1 to 28, which every month has.`
- `Invalid cron expression: the scheduler finds no date that matches it, so the schedule would never run. …`
- `Invalid cron expression. Use standard 5-field format: min hour dom mon dow`

**Cause:** The cron library Ansilume uses accepts some expressions that it
then never runs, or runs at other times than they say:

- a range that runs backwards never matches;
- the library looks for the next minute or hour by walking a list in its
  own order: it sorts the minutes as text and takes the hours as written,
  so `0 18,6 * * *` ran at 18:00 only and `5-10,45 * * * *` at minute 45
  only, and it walks a minute list with `*` from it as minute 0, so
  `*,5 * * * *` ran at minutes 0 and 5 only;
- it reads Sunday as 7 only where the text holds the digit 7, and turns a
  start of 7 into 0 and an end of 0 into 7: `SAT-SUN` ran on Saturdays
  only, `7-7` and `0-0` on every day, `SUN-SAT` never, and `0-7/2` never
  on a Sunday. `5-0` it ran from Friday to Sunday, as cronie does, but
  Vixie cron refuses it as a range that runs backwards;
- it runs many steps as large as the field's largest value or larger at
  other times than cron: `*/90` ran at minute 30, `*/59` at minute 59
  only;
- a step after names counts from 0 (`MON-FRI/2` ran on Sundays), and `W`
  after a day a month lacks took a day of the next month.

An expression that no date matches, such as the 30th of February, has no
run at all. Older versions saved such schedules, which then never ran, or
skipped runs (see
[updating.md](updating.md#after-updating-from-2122-or-older-php-83-cron-expressions-unreadable-files)).
When both the day of the month and the day of the week are restricted, the
schedule runs on days that match either, also when one of them starts with
`*` (such as `*/2`), where cron itself runs on days that match both. How
the runs fall on the nights the clocks change:
[below](#a-schedule-ran-twice-or-an-hour-early-on-the-night-the-clocks-went-back).

**Fix:** Write the part the message names as it suggests: the smaller value
of a range first, and a range that would wrap around split, each part with
the range's step (`0-2,22-23` instead of `22-2`, `0-10/5,50-59/5` instead
of `50-10/5`; where the message gives no split, as when the parts would be
out of order in their list, the next save says how to order it); the
values of a minute or hour list in ascending order, in a minute list with a
range or a step with minutes of two digits where the message writes them
so (`05,10-50`: the scheduler sorts `5,10-50` as text, `10-50` first); for
a list Ansilume finds no way to write beside the other fields, shorter
other fields (`*` instead of `1,2,3,4,5,6,7,8,9,10,11,12`) or two schedules
whose values have none in common, so that no run is launched twice; `*`
alone instead of a minute list with `*`;
Sunday as 0 at the start of a range (`0-SAT`), as 7 at its end (`SAT-7`,
`5-7`), and alone as 0 or 7; the days of the week stepped from 0 to 6
(`0-6/2`); a step smaller than 59 for minutes, 23 for hours, 31 for days of
the month, 12 for months and 7 for days of the week, or the values listed
(`0,30`); a range with a step in numbers (`1-5/2`); `W` with a day from 1
to 28, or `L` for the last day of the month; and a day of the month that
the months of the expression have. A schedule saved with such an
expression before the update keeps it until it is saved again, and runs as
before: `php yii schedule/check` lists them (in the app container:
`docker compose exec -u www-data app php yii schedule/check`), the schedules list marks
each with **Change needed** (the reason in its tooltip), the schedule's page
and its edit form say why, and the API gives the reason as
`cron_expression_problem`. Every save of such a schedule, a rename too,
shows the reason as the error of the cron expression until the expression
is changed; enabling and disabling it still work.

## A schedule ran twice, or an hour early, on the night the clocks went back

**Symptom:** With 2.12.2 or older, on the night the clocks went back (in
Central Europe the last Sunday of October, in the USA the first Sunday of
November), a schedule at the hour that comes twice ran twice: `0 2 * * *`
in `Europe/Berlin` at 2:00 CEST and again at 2:00 CET. One at the hour
after it ran an hour early as well: `0 3 * * *` at 2:00 CET and at 3:00 CET,
in `America/New_York` `0 2 * * *` at 1:00 EST and at 2:00 EST. In
`Africa/Casablanca`, `Antarctica/Troll` and `Australia/Lord_Howe`, whose
clocks change otherwise, a schedule also skipped its run on the night of a
change, ran at another time, showed no next run, or, every 15 minutes on
Lord Howe Island, ran every minute for a quarter of an hour.

**Cause:** Ansilume asked its cron library for the next run in the
schedule's timezone. The library reads the hour that comes twice as the
hour after it too, and miscounts around the clock changes of those three
timezones.

**Fix:** Update. Ansilume asks the library only which wall-clock times an
expression holds, in UTC. A time that comes twice runs once, the first
time, as cron runs it, unless the minute or the hour field starts with `*`
(such as `*/15 * * * *` or `0 * * * *`): such a schedule runs at each time
that comes, the repeated ones too, but only at the times it holds
(`*/15 3 * * *` no longer runs from 2:00 to 2:45 CET). A time the clocks
skip, 2:00 to 2:59 in Central Europe when they go forward, runs when it
would have come by the clock before the change, 2:00 at 3:00 CEST and 2:30
at 3:30 CEST, as before; cron runs it right after the change. The update
computes anew the next run of every schedule that is not
yet due, so one that 2.12.2 computed for the coming night moves to its time
(see
[updating.md](updating.md#after-updating-from-2122-or-older-php-83-cron-expressions-unreadable-files)).

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
- `Re-launch failed: ...`: the launch itself failed, with the reason, for
  example an approval rule with too few approvers besides you (see
  [Launch refused: too few approvers besides the launcher](#launch-refused-too-few-approvers-besides-the-launcher)).
  A re-launch runs as you, so you never approve it.

A user who gets `403` lacks `job.launch`, which re-launch needs since this
release; `job.cancel` was enough before.

**Fix:** Launch the job template from its launch page and enter the password
answers, or give the user `job.launch`.

## Saving a job template is refused: it has an approval rule

**Symptom:** Saving a job template, in its edit form or with
`PUT /api/v1/job-templates/{id}`, is refused with
`This job template has an approval rule: only admins may change what it runs (approval-rule.update). You may change its name and description.`
(an error page in the web UI, `403` from the API), and nothing is saved.
The template page and the edit form say
`This job template has an approval rule: only admins may change what it runs (approval-rule.update). You may still change its name and description, launch it and manage its trigger token.`,
and the form shows every field but the name and the description disabled.

**Cause:** The template has an approval rule, and you lack
`approval-rule.update`. A schedule, a trigger or a workflow launches the
template's jobs as the user it runs as, who never approves them: if you
could change what the template runs, you could approve alone what you
chose. Every field but the name and the description counts: the project,
inventory, playbook, credentials and their order, extra vars, survey,
runner group, timeout and the Ansible settings. A field sent with the value
it has is no change, so a client may send back what
`GET /api/v1/job-templates/{id}` returned with a new name. The save checks
the template as it is at that moment, so such a change is refused as well
when the form was opened before an admin gave the template its rule.

**Fix:** Ask an admin to make the change. Rename the template or change its
description yourself, and launch it, clone it or manage its trigger token
as before. Deleting it needs `job-template.delete`, as before, which the
operator role does not grant. A clone keeps the rule, so it is locked for
you as well. A change of the rule itself is refused with
`Only admins may assign or remove an approval rule: that needs approval-rule.update.`
first; see
[updating.md](updating.md#after-updating-from-290-or-older-approvals-and-workflows).
When the template has no rule of its own but its inventory has one, see
[Saving a job template is refused: its inventory has an approval rule](#saving-a-job-template-is-refused-its-inventory-has-an-approval-rule).

## Saving a job template is refused: its inventory has an approval rule

**Symptom:** Saving a job template, in its form or with `POST` or
`PUT /api/v1/job-templates`, is refused with one of these, as an error
page in the web UI and `403` from the API, and nothing is saved:

- `The inventory of this job template has an approval rule: only admins may change what it runs (approval-rule.update). You may change its name and description.`:
  the template's inventory has an approval rule. Its page and its edit
  form say
  `The inventory of this job template has an approval rule: only admins may change what it runs (approval-rule.update). You may still change its name and description, launch it and manage its trigger token.`,
  and the form disables every field but the name and the description.
- `The selected inventory has an approval rule: only admins may give a job template an inventory with an approval rule (approval-rule.update).`:
  the template, a new one too, would get an inventory with an approval
  rule. The form does not offer you such inventories, and says
  `Inventories with an approval rule are not listed: only admins may give a job template such an inventory.`

**Cause:** Every launch of a job template on an inventory with an approval
rule waits for the inventory's rule, and you lack `approval-rule.update`. A
schedule, a trigger or a workflow launches the template's jobs as the user
it runs as, who never approves them: if you could change what the
template runs, or give it such an inventory, you could approve alone what
you chose. Every field but the name and the description counts, the
inventory too, so moving the template to an inventory without a rule is
refused as well. A field sent with the value it has is no change.

**Fix:** Ask an admin to make the change. Rename the template or change
its description yourself, and launch it, clone it or manage its trigger
token as before; a clone keeps the inventory, and so the lock. An
inventory you may not see answers `The selected inventory does not exist.`,
gated or not. When the template has an approval rule of its own besides,
see
[Launch refused: two approval rules meet](#launch-refused-two-approval-rules-meet).

## Saving an inventory is refused: it has an approval rule

**Symptom:** Saving an inventory, in its edit form or with
`PUT /api/v1/inventories/{id}`, is refused with
`This inventory has an approval rule: only admins may change its hosts (approval-rule.update). You may change its name and description.`
(an error page in the web UI, `403` from the API), and nothing is saved.
The inventory's page and its edit form say
`This inventory has an approval rule: every launch of a job template that uses it waits for approval. Only admins (approval-rule.update) may change its hosts or delete it, give a job template this inventory, or change what the job templates that use it run. You may still change its name and description, and parse it.`,
and the form shows its type, content, project and source path disabled.

**Cause:** The inventory has an approval rule, and you lack
`approval-rule.update`. Every launch of a job template that uses it waits
for the rule, and the schedules, triggers and workflows of these templates
launch as the users they run as, who never approve the jobs: if you could
change the hosts, you could approve alone where they run. Its type,
content, source path and project count. A field sent with the value it has
is no change, so a client may send back what
`GET /api/v1/inventories/{id}` returned with a new name. The save checks
the inventory as it is at that moment, so such a change is refused as well
when the form was opened before an admin gave the inventory its rule.

**Fix:** Ask an admin to make the change. Change the name or the
description yourself, and parse the inventory, as before. A change of the
rule itself is refused with
`Only admins may assign or remove an approval rule: that needs approval-rule.update.`
first; see
[updating.md](updating.md#after-updating-from-2111-or-older-approval-rules-for-inventories).
For a file or dynamic inventory, its hosts come from the project's
repository when a job runs, which the rule does not lock (see
**What an approval rule does not stop** there).

## Deleting an inventory is refused

**Symptom:** Deleting an inventory shows, as a danger flash on its page,
and `DELETE /api/v1/inventories/{id}` answers `409` with, such as
`Inventory "web-prod" is in use by 2 job template(s) and 1 deleted job template(s). Point its job templates at another inventory first. A deleted job template keeps its inventory for the history of its jobs until its project is deleted, so deleting the template does not free it.`,
with the job templates in `error.used_by`; or it answers `403` with
`This inventory has an approval rule: only admins may delete it (approval-rule.update).`
Nothing is deleted.

**Cause:** Live job templates use the inventory, or deleted ones still
refer to it: a deleted job template keeps its inventory, for the history of
its jobs, until its project is deleted. Up to 2.11.1 such a delete ended
in a server error. The `403`: the inventory has an approval rule, and you
hold `inventory.delete` but not `approval-rule.update`; of the built-in
roles only admin holds `inventory.delete`.

**Fix:** The inventory's page lists the job templates that use it in its
card **Job templates**, and counts those in projects you may not see and
the deleted ones; `GET /api/v1/inventories/{id}` answers the same as
`used_by`. Give each live one another inventory, then delete the
inventory. An inventory that only deleted job templates use stays until
their project is deleted, a known limit (see
[updating.md](updating.md#after-updating-from-2111-or-older-approval-rules-for-inventories)).
Only an admin deletes an inventory with an approval rule.

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

## App container does not start: the database check could not run

**Symptom:** The app container restarts again and again, and
`docker compose logs app` shows, after
`[entrypoint] waiting for database...`:

```
[entrypoint] ERROR: the database check could not run (php failed)
[entrypoint] Check PHP's error log /var/log/php_errors.log in this container for details.
```

A line such as `Killed` or `Segmentation fault (core dumped)` right above
it says that PHP was killed or crashed.

**Cause:** PHP itself failed while it checked whether the database answers:
it stopped at an error, crashed, or was killed, for example by the kernel's
out-of-memory killer. It is not the database: a database that does not
answer gives `[entrypoint] ERROR: database not reachable after 60s`, and
rejected credentials give `database credentials rejected`. Up to 2.13.0 the
container ended at this point without a word.

**Fix:** Read PHP's error. While the container restarts,
`docker compose exec` cannot reach it, but `docker compose cp` can:

```bash
docker compose cp app:/var/log/php_errors.log php_errors.log
tail -20 php_errors.log
```

If the file does not exist or holds nothing new, PHP was killed or crashed
before it could log. Whether the out-of-memory killer did it: Docker resets
`OOMKilled` each time the container starts again, so ask while it waits to
restart, more than once if it says `false`, or look in the kernel log (which
may need root):

```bash
docker inspect --format '{{.State.Status}} {{.State.OOMKilled}}' $(docker compose ps -aq app)
journalctl -k | grep -i 'out of memory'
```

## A container does not start after the update: composer install failed

**Symptom:** In a source installation, after `git pull`, or with the
deploy role after a build that failed, a container that starts again does
not come up, and its log (`docker compose logs app`) shows
`Root composer.json requires php >=8.5 but your php version (8.3.36) does not satisfy that requirement.`,
then `[entrypoint] ERROR: composer install failed`; the line after it,
`[entrypoint] Check disk space and network connectivity.`, does not apply
here.

**Cause:** The container still runs the image of the release before, on
PHP 8.3, while the code it runs, from the install directory, requires PHP
8.5: only a build replaces the image, and its `composer install` refuses
the new `composer.lock`.

**Fix:** Build the images and recreate the containers, steps 2 and 3 of
[Manual update — source (git)](updating.md#manual-update--source-git);
with the deploy role, fix why its build failed and run it again. To go
back instead, see
[After updating from 2.13.1 or older](updating.md#after-updating-from-2131-or-older-php-85-time-zone-data-csv-exports-whole-numbers).

## A runner installed without Docker does not start after the update

**Symptom:** After its code was updated, a runner installed without Docker
(see [runners.md](runners.md#running-without-docker)) exits at once, and
systemd starts it again and again (`journalctl -u ansilume-runner`), with
`Composer detected issues in your platform: Your Composer dependencies require a PHP version ">= 8.5.0". You are running 8.4.27.`
or with
`Error: Call to undefined function app\components\http_clear_last_response_headers()`.
Or `composer install` stopped with
`Root composer.json requires php >=8.5 but your php version (8.4.27) does not satisfy that requirement.`
Or the runner exits with `ERROR: API_URL environment variable is required.`
although its unit sets `API_URL`.

**Cause:** The PHP that starts the runner, or that ran `composer install`,
is older than PHP 8.5, which Ansilume requires since the update from
2.13.1: Debian 13 ships PHP 8.4, Ubuntu 24.04 PHP 8.3, RHEL 9 at most 8.3.
The first message comes when `composer install` ran on PHP 8.5 but the
service starts another `php`, such as the one `/usr/bin/php` names. After
the third one, or when `composer install` was not run, the dependencies of
the release before stay in place, which the new code is not made for:
their platform check lets PHP 8.3 start it, and the runner exits at its
first request with the second message, as the new code calls a function of
PHP 8.4 there. PHP 8.4 runs the new code with them without a message: the
runner starts and runs jobs on the dependencies of the release before
until a later release needs PHP 8.5.
`ERROR: API_URL environment variable is required.` comes when the runner
takes its settings from its environment, from `Environment=` lines of its
unit: PHP hands the environment to the runner only when `variables_order`
in its php.ini contains `E`, and PHP 8.5 brings a php.ini of its own,
which sets `GPCS`, as the php.ini of the distributions do, where the
php.ini of the old PHP may have set `EGPCS`.

**Fix:** Install PHP 8.5 with `pcntl`, `posix`, `zip`, `curl` and `ffi`
(where from: [runners.md](runners.md#prerequisites)), check the PHP the
service starts (`ExecStart` of its unit), run
`composer install --no-dev` with that PHP, and start the runner again
(`sudo systemctl restart ansilume-runner`). Leave out
`--optimize-autoloader` where `runtime/` belongs to the runner's user:
with it, Composer stops at that directory with `Permission denied`. For
`ERROR: API_URL environment variable is required.`, put the runner's
settings in `.env` of its directory and remove them from the unit (see
[runners.md](runners.md#setup)).

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
`docker compose exec -u www-data app php yii maintenance/reset-survey-secret-scrub`. Its
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
without Docker: `git pull`, `composer install --no-dev` and restart the
process, see [runners.md](runners.md#setup); dev checkout: `docker compose up -d
--force-recreate`, which also restarts the queue worker and lets `app` run the
migrations). Without `runner-group.view` the card shows only how many runners
are affected.

## A job fails at once: git cannot fast-forward the runner's checkout

**Symptom:** A job fails before Ansible runs, and its log says
`Git sync failed (exit 128, …)` with
`command: git -C /var/www/runtime/projects/5 merge --ff-only FETCH_HEAD`
and `fatal: refusing to merge unrelated histories` or
`fatal: Not possible to fast-forward, aborting.`

**Cause:** The runner's checkout of the project holds a history that the
branch no longer has: the project's URL names another repository now, or
the branch was force pushed. From 2.11.0 on, a runner points its checkout
at the project's current URL before every job, fetches the branch and only
fast-forwards; it never discards what the checkout holds. Up to 2.10.1 a
runner whose project's URL had changed kept fetching from the URL its
checkout was cloned with; a force push failed its fast-forward then too.

**Fix:** Remove the project's checkout on that runner
(`/var/www/runtime/projects/<project ID>`, in its container if it runs in
one); its next job clones the repository again. The server resets its own
checkout to what it fetched and needs nothing. See
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**Security: repository URLs in syncs and logs**.

## Project sync fails: the branch starts with "-"

**Symptom:** The sync of a project fails with, for example,
`git command failed (exit 128): fatal: couldn't find remote ref -x`, and
its jobs fail with `Git sync failed (exit 128, …)` and the same message of
git. Saving the project, in its Edit form or with
`PUT /api/v1/projects/{id}`, is refused with
`The branch cannot start with "-" or contain whitespace.`

**Cause:** The project's branch starts with `-`. From 2.11.0 on, git gets
the branch after `--` and takes it for a branch name, never for one of its
options; up to 2.10.1 git read such a branch as an option (see
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**Security: a project's branch read as an option of git**). From 2.11.0
on, for git projects, the project form and the API refuse such a branch,
and any branch with whitespace, with
`The branch cannot start with "-" or contain whitespace.`; a git project
saved with one before keeps it, and saving it as a git project is refused
with the same text until its branch is changed. The branch of a manual
project is not checked: no sync reads it.

**Fix:** Give the project the branch it should check out and save it:
saving a git project queues a sync.

## Project page: the web server cannot read paths

**Symptom:** The project page says
`The web server cannot read these paths in the project directory, so the playbooks and the repository structure below may be incomplete: …`
with the paths, or
`The web server cannot read the project directory, so its playbooks and repository structure are not shown. …`,
or, for a manual project,
`The web server cannot find the project directory /srv/ansible/project, or may not enter a directory above it. …`.
The repository structure marks those paths `(not readable)`.

**Cause:** The page lists the project directory and reads the start of its
YAML files as the web server's user (`www-data` in the images): a directory
it may not list, a file it may not read, or a directory it may list but not
enter, whose entries it then cannot examine; the list names the directory
it may enter but not list as `the project directory itself`. A manual
project's directory is any directory on the server, so it can belong to
another user, or lie below a directory the web server may not enter, such
as `/root`. Before the update such a path made the page answer with a
server error. Symlinks are shown, marked 🔗, and the page never follows
one into a directory: a repository with a link to a directory outside the
project, such as `etc -> /etc`, made the page list that directory, and
failed on the parts of it the web server may not read. A symlinked
playbook is listed under Detected Playbooks only when it leads to a file
inside the project. While the project syncs, the page names nothing: the
sync replaces files as the page reads them; a file that is removed while
the page reads it, as by a `git pull` in a manual project's directory, is
left out and not named, and one that git writes anew meanwhile is read once
it is back.

**Fix:** Give the web server's user permission to read the files and to
read and enter the directories, for example
`chmod -R a+rX /srv/ansible/project` or `chown -R www-data /srv/ansible/project`
on the server (in the app container for the Docker installations), and
permission to enter every directory above the project directory
(`chmod a+x` on each), then reload the page. A file that should stay
private can stay so; the page then keeps naming it.

## Deleting a project is refused

**Symptom:** Deleting a project shows
`Cannot delete "web": 1 job template(s) still reference this project. Remove or reassign them first.`
as an error, `DELETE /api/v1/projects/{id}` answers `422` with it, and the
project stays.

**Cause:** Job templates that are not deleted belong to the project.
Deleted job templates do not count: the delete removes them with the
project, after it canceled their waiting jobs. A job template created in
the project, or moved into it, while the delete ran counts too: the
database refuses the delete then, and the templates are counted again.
Until the update to approvals and workflows such a template was deleted
together with the project.

**Fix:** Delete the job templates of the project, or move them to another
project, then delete it again. Moving a job template with an approval rule
to another project is for admins (see
[Saving a job template is refused: it has an approval rule](#saving-a-job-template-is-refused-it-has-an-approval-rule)).

## Deleting a runner group is refused

**Symptom:** Deleting a runner group shows
`Cannot delete "eu-west": 2 runner(s) still belong to this group.` or
`Cannot delete "eu-west": 3 job template(s) reference this group.` as an
error, `DELETE /api/v1/runner-groups/{id}` answers `422` with it, and the
group stays.

**Cause:** Runners belong to the group, or job templates use it; deleted job
templates do not count. Deleting the group would delete its runners and
take it off the templates, and no runner claims the jobs of a template
without a runner group. The API refused such a delete already; the web UI
deleted the group until the update to approvals and workflows.

**Fix:** Move the runners to another group, with **Move** on the group's
page or `POST /api/v1/runners/{id}/move`, or delete them, and choose
another runner group for every job template that uses the group; then
delete it. Changing the runner group of a job template with an approval
rule is for admins (see
[Saving a job template is refused: it has an approval rule](#saving-a-job-template-is-refused-it-has-an-approval-rule)).

## Changing a user is refused

**Symptom:** The Edit User form, opened or saved, **Deactivate**,
**Activate**, **Delete**, or `PUT` or `DELETE /api/v1/users/{id}` answers
`403` with
`You cannot change or delete this user: they are a superadmin, or a role assigned to them grants permissions you do not hold.`,
`You cannot change or delete this user: a role assigned to them is or contains the admin role, which only a superadmin or an admin may assign.`
or
`You cannot change or delete this user: their teams give them more access to a project than you have.`,
and the users list and the user page offer no **Edit**, **Deactivate**,
**Activate** or **Delete** for that user.

**Cause:** Only superadmins change superadmins. A user who is no
superadmin changes a user only when they may assign every role assigned to
that user: a role that grants no permission they do not hold, and the
`admin` role, or a role that contains it, only while they hold the `admin`
role. Unless they hold the `admin` role, their own teams must also give
them every team-restricted project that the user's teams give the user,
with at least the same role (operator over viewer). A user who holds every
permission of the user's roles, but neither is a superadmin nor holds the
`admin` role, gets the second refusal for a user who is no superadmin and
holds the `admin` role or a role that contains it, whatever the user's
teams: the `admin` role also gives every team-restricted project.

**Fix:** Let a superadmin or an admin make the change, or give the user who
makes it what the refusal names: the permissions of the user's roles, or a
team with the user's projects and roles. Roles and the superadmin flag are
given by the same rule: the Role field, `POST /api/v1/users` and
`PUT /api/v1/users/{id}` refuse
`You cannot assign the role "admin": it grants permissions you do not hold.`
and
`You cannot assign the role "admin": it is or contains the admin role, which only superadmins and admins may assign.`,
and the API `Only a superadmin can set or clear is_superadmin.` The only
active superadmin edits their own account, but neither clears their
superadmin flag nor deactivates their account, and is not deleted: the
form and **Delete** answer `403`
`Cannot remove, demote or deactivate the only active superadmin account.`,
the API `422` `Cannot demote the only active superadmin.`,
`Cannot deactivate the only active superadmin.` or
`Cannot delete the only active superadmin.` An inactive superadmin does
not count, as they cannot sign in, and may be demoted, deactivated or
deleted. Make another user a superadmin, or reactivate one, first; when no
superadmin can sign in at all, see
[No superadmin can sign in any more](#no-superadmin-can-sign-in-any-more).
See
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**Security: who may change other users' accounts**.

## No superadmin can sign in any more

**Symptom:** Nobody can sign in as a superadmin: every account with the
superadmin flag is inactive, or no account has the flag any more. The
users list and the user page offer an admin who is no superadmin no
**Edit** and no **Activate** for such a user, and
`PUT /api/v1/users/{id}` answers them `403`
`You cannot change or delete this user: they are a superadmin, or a role assigned to them grants permissions you do not hold.`

**Cause:** From 2.11.0 on, only superadmins change superadmins, so once no
superadmin can sign in, nothing in the web UI or the API makes one active
again. That happens when:

- `php yii ldap/sync` deactivates an LDAP account, a superadmin's too,
  when the directory's user search runs and finds no entry for it, such as
  one removed from the directory. It revokes the account's roles and
  deletes its API tokens, keeps its superadmin flag, and records
  `ldap.user.disabled` in the audit log. Up to 2.10.1 it did so for every
  LDAP account whose lookup failed as well: all of them while the service
  bind failed or LDAP was enabled without a host or base DN, and each one
  whose user search failed; from 2.11.0 on, a lookup that fails changes
  nothing.
- Up to 2.10.1, **Deactivate** (with `user.delete`) deactivated any other
  superadmin, and `PUT /api/v1/users/{id}` with `status` 0 (with
  `user.update`) any superadmin, the last active one too. An inactive
  superadmin counted as another superadmin, so the last active superadmin
  could also take their own flag while the only other superadmin was
  inactive.
- Two superadmins deactivate, demote or delete each other at the same
  moment: the checks take no lock.
- The database was changed directly.

**Fix:** An LDAP superadmin whom `ldap/sync` deactivated while the
directory could not be read (up to 2.10.1) signs in again once it can be
read: the sign-in, like the next `ldap/sync` that finds them, activates
the account (`ldap.user.reenabled`), with the roles their groups map to.
That holds while `user.ldap_sync_disabled` marks the account, as the
migration of 2.11.0 does for an account whose latest status entry in the
audit log is `ldap.user.disabled`; an account changed in Ansilume after
that stays inactive whatever the directory says (see
[An LDAP user cannot sign in: the account was deactivated in Ansilume](#an-ldap-user-cannot-sign-in-the-account-was-deactivated-in-ansilume)).
Otherwise, while no active superadmin exists, run

```bash
docker compose exec -u www-data app php yii setup/admin <username> <email> [password]
```

It creates an active superadmin with the `admin` role, and prints a
generated password when none is given. Use a username and an email that no
user has, not those of an old superadmin: a taken one fails with
`Failed to create user: …` and creates nothing. The run is audited as
`user.created` with the source `console:setup/admin`, and leaves the
seeded Selftest and Demo content alone. Sign in as the new superadmin,
then activate the old superadmins again (**Activate**) or remove them
(clear their flag, or **Delete** those that nothing refers to). While an
active superadmin exists, `setup/admin` answers
`An active superadmin already exists. Aborting.` and changes nothing: sign
in as that superadmin instead. See
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**Security: who may change other users' accounts**.

## An LDAP user cannot sign in: the account was deactivated in Ansilume

**Symptom:** The sign-in of an LDAP user is refused with
`Incorrect username or password.`, though the directory has the user and
the password is right: `php yii ldap/test-user <username>` finds them. The
audit log records `ldap.login.failed` with the reason
`Account deactivated in Ansilume.`, `php yii ldap/sync` prints
`- <username>: deactivated in Ansilume, not by ldap/sync → left inactive`,
and the user page says
`Deactivated in Ansilume: the directory does not activate the account again, neither an LDAP sign-in nor ldap/sync.`
under the status.

**Cause:** This is expected. The account was deactivated in Ansilume
(**Deactivate**, the Edit User form or `PUT /api/v1/users/{id}`), or the
update to 2.11.0 found no `ldap.user.disabled` as its latest status entry
in the audit log. From 2.11.0 on, an LDAP sign-in or `ldap/sync` activates
again only an account that `ldap/sync` deactivated because the directory
no longer had the user (`user.ldap_sync_disabled`), so that an admin can
lock out an LDAP user who is still in the directory. Up to 2.10.1 the
user's next sign-in, or the next `ldap/sync`, activated the account again.

**Fix:** If the user should sign in again, activate the account on their
page (open it from the Users page, then **Activate**) or with
`PUT /api/v1/users/{id}` and `status` 10. They then sign in with their
directory password and get the roles their groups map to. See
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**Security: LDAP accounts deactivated in Ansilume**.

## LDAP fails: "PHP ldap extension is not loaded."

**Symptom:** With `LDAP_ENABLED=true` and a host and base DN set,
`php yii ldap/test-connection`, `php yii ldap/test-user <username>` and
`php yii ldap/sync` (at its first LDAP account) stop with
`Error: PHP ldap extension is not loaded.` and exit with `1`. The sign-in
of an LDAP user, or of a username that is no local account, shows the
error page (`500`), and `/api/v1/admin/ldap/test` answers `500`. Local
accounts sign in as before. The application log has an
`app\services\ldap\LdapException` with `PHP ldap extension is not loaded.`

**Cause:** The PHP of the container has no `ldap` extension. The prebuilt
app image `ghcr.io/ansilume/ansilume` had none up to 2.10.1, so LDAP never
worked with `docker-compose.prebuilt.yml`, the quickstart or the deploy
role's prebuilt mode.

**Fix:** Update to 2.11.0 or later (`docker compose pull && docker compose
up -d`, or quickstart `--update`). Then
`docker compose exec app php -m | grep -x ldap` prints `ldap`, and
`php yii ldap/test-connection` reaches the directory. An image of your own
built on `ghcr.io/ansilume/ansilume` 2.10.1 or older needs `libldap2-dev`
and `docker-php-ext-install ldap`, as `docker/php/Dockerfile.prod` has
them from 2.11.0 on. See [ldap.md](ldap.md#container-images).

## ldap/sync fails: a lookup or the service bind failed

**Symptom:** `php yii ldap/sync` exits with `1` and prints, for a user,
`- jdoe: lookup failed → left unchanged (User search failed: No such object)`,
or stops with
`[ldap] Service bind failed (Service account bind failed: Can't contact LDAP server): sync stopped, 12 user(s) left unchanged.`;
or it exits with `78` and prints
`[ldap] LDAP is enabled but not fully configured (host or base DN missing): sync stopped, no user changed.`

**Cause:** The directory could not be read, or LDAP is enabled without a
host or base DN, so `ldap/sync` left those accounts, their roles and their
API tokens as they are: a lookup that fails says nothing about the user.
A user or group search that fails or does not run to the end (a referral
to another server, a size limit), and a user search that matches more than
one entry, fail the lookup of that user; a failed service bind stops the
run, as every further lookup would fail too. Up to 2.10.1 such a run
deactivated the accounts (see
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**ldap/sync and a directory that cannot be read**).

**Fix:** Run `php yii ldap/test-connection` and
`php yii ldap/test-user <username>`, which name the failure. Check
`LDAP_HOST`, the service account (`LDAP_BIND_DN`, `LDAP_BIND_PASSWORD`)
and `LDAP_BASE_DN`: a referral means that another server holds the base
DN. `LDAP_USER_FILTER` must match exactly one entry for each user, and
`LDAP_GROUP_FILTER` must run without an error. Then run `ldap/sync` again.
See [ldap.md](ldap.md#console-commands).

## Changing a role is refused

**Symptom:** The New Role or Edit Role form shows
`You cannot grant the permission "user.update": you do not hold it.` under
Permissions, or `POST /api/v1/roles` or `PUT /api/v1/roles/{name}` answers
`403` with it; or the Edit Role form, opened or saved, **Delete**, or `PUT`
or `DELETE /api/v1/roles/{name}` answers `403`
`You cannot change or delete this role: you may not assign it, as it grants more than you hold.`,
and the role page offers no **Edit** or **Delete**.

**Cause:** A user who is no superadmin gives a role only permissions they
hold, through their roles or the default role, and changes or deletes only
a role they may assign: one that grants no permission they do not hold,
and the `admin` role, or a role that contains it, only while they hold the
`admin` role. The forms disable the permissions they do not hold and say
`Permissions you do not hold are disabled: you can grant only what you hold.`
Removing permissions from a role they may change is never refused.

**Fix:** Let a superadmin or an admin make the change, or have them give the
user who makes it the permission first. See
[updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs),
**Security: who may change roles**.

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

## Artifact download answers 404

**Symptom:** **Download** of an artifact answers 404 with
`Artifact file no longer exists on disk.` or
`Artifact file could not be read.`; the **Preview** of a text artifact
says `Failed to load preview.`, that of an image stays empty and that of a
PDF shows the 404 page, and **Download All (zip)** answers 404 with
`No artifacts to download.`, or its archive lacks some files. The API
answers the same (`GET /api/v1/jobs/{id}/artifacts/...`). The app log has
`Download All of job #42 leaves out artifacts 7, 9: their files are gone or cannot be read.`
or
`ArtifactService: the file of artifact #7 of job #42 cannot be read: …`.

**Cause:** The job's artifact records outlived their files, as after a lost
`runtime/artifacts` volume, a cleanup by hand or a restored database
(`Artifact file no longer exists on disk.`), or the web server's user may
not read the files or enter their job directory or a directory above it,
such as `runtime/artifacts`, as after a restore by root
(`Artifact file could not be read.`, and the log names the directory). With the prebuilt images, and the deploy role
with `ansilume_use_prebuilt_images`, `runtime/artifacts` is on no volume:
the artifacts older versions collected in the queue-worker stayed in that
container, and recreating a container removes the files. Before the update
a file that could not be read, or a job with none left, made these
downloads answer with a server error, and so did the preview of a text
artifact that is not UTF-8, which the preview now shows with `?` for what
it cannot read.

**Fix:** Restore the files from a backup and give the web server's user
(`www-data` in the images) permission to read them and to enter their
directories, for example
`chown -R www-data /var/www/runtime/artifacts` in the app container.
Artifacts whose files are gone for good stay listed until the artifact
retention removes them (`ARTIFACT_RETENTION_DAYS`,
`ARTIFACT_MAX_JOBS_WITH_ARTIFACTS`, see [artifacts.md](artifacts.md)).

## A page or an API call fails with a server error

**Symptom:** A page shows the error page with `500`, an API call answers
`500` in the framework's shape (`"status": 500`), or a console command
stops with `Error: ` and one line (with debug mode, with the whole error),
for example in `docker compose logs schedule-runner`.

**Cause:** An error that nothing in Ansilume caught. The application log of
the container that ran the code has it, with its file, line and stack
trace: the app container for the web UI and the API, the schedule-runner
for schedules and the maintenance run, the queue-worker for project syncs.
In a git checkout they all write `runtime/logs/app.log`;
with the prebuilt images, the log is inside each container, for example:

```bash
docker compose exec app tail -n 200 /var/www/runtime/logs/app.log
```

When the log has no line for the error, and none since the app container
started, `www-data`, the user of php-fpm's workers, may not be able to
write `app.log`: Yii drops every line of a process that cannot append to
its log file. Check the owner:

```bash
docker compose exec app ls -l /var/www/runtime/logs/
```

`root root` for `app.log` is that state, in which every new app container
of 2.13.1 or older started, as each update of the prebuilt images (and of
the deploy role with `ansilume_use_prebuilt_images`) makes one: its
entrypoint ran the migrations as root, which created the file before
php-fpm started. How to hand the file back, see
[App or queue-worker container is unhealthy: the log file cannot be written](#app-or-queue-worker-container-is-unhealthy-the-log-file-cannot-be-written).

A database error is written without its message, which would carry the SQL
statement with its values: by its class, SQLSTATE and driver error code,
for example `yii\db\Exception (SQLSTATE 40001, driver error 1213)`, also
when another error wraps it; that one follows after `Next`, as its class
and `(message withheld: it wraps a database error)`. A connection that
failed or was lost also shows the driver's text, such as
`yii\db\Exception (SQLSTATE HY000, driver error 2002): Connection refused`.
A connection that a MySQL server of 8.0.24 or later closed after it was
idle longer than its `wait_timeout` or `interactive_timeout` reads, since
PHP 8.4,
`yii\db\Exception (SQLSTATE HY000, driver error 4031): The client was disconnected by the server because of inactivity. See wait_timeout and interactive_timeout for configuring this behavior.`;
PHP 8.3 reported it as `2006`, and MariaDB, the database of the compose
files, still does.
Without debug mode (`YII_DEBUG`), a console command prints such an error
as one line, such as
`Error: RuntimeException (message withheld: it wraps a database error); caused by yii\db\Exception (SQLSTATE 40001, driver error 1213)`;
with debug mode it prints the whole error, its message with the statement
and its values included, while the application log leaves the message out
in both modes. Before the update to approvals and workflows the log and
the one line held the statement with its values (see
[updating.md](updating.md#after-updating-from-290-or-older-approvals-and-workflows)).

No process of Ansilume keeps a database connection while it waits: php-fpm
opens one for each request, the schedule-runner one for each run of
`schedule/run` and of `maintenance/run`, and the queue-worker one for each
project sync, which it closes when the sync ends; the runners have none.
Up to 2.13.1 the queue-worker kept its connection for its whole life:
once the queue had sat idle longer than the database's `wait_timeout`
(28800 seconds, 8 hours, by default on MariaDB and MySQL), as overnight,
the database had closed it, and every project sync of the server failed
until the queue-worker restarted, with
`[4] app\jobs\SyncProjectJob (attempt: 1, PID: 1) is finished with error: PDOException (SQLSTATE HY000, driver error 2006): MySQL server has gone away`
or `4031` in its log; the project showed `syncing` until the maintenance
run gave up on the sync after 15 minutes.

**Fix:** SQLSTATE `40001` with driver error `1213` is a deadlock and `1205`
a lock wait timeout, which usually pass: try again. `HY000` with `2002`,
`2006` or `4031` is a database that cannot be reached or a lost connection:
check the `db` container, or your external database, and the database
settings. The next page, run or project sync opens a new connection, so no
container, the queue-worker included, needs a restart for it. The
`wait_timeout` and `interactive_timeout` of the database need to be longer
than a page or a project sync takes (a git command of a sync may take 300
seconds), not than the queue sits idle: keep their default of 28800
seconds. For
any other error, the class, the file and the line say where it struck; the
stack trace shows the calls without their arguments. Debug mode
(`YII_DEBUG`) shows the messages of errors on the error page, in the
API's answers and in what console commands print, where they can hold
values: never turn it on in production (see
[installation.md](installation.md)).

## App or queue-worker container is unhealthy: the log file cannot be written

**Symptom:** `docker compose ps` shows the app or the queue-worker as
`unhealthy`. Its health check prints the following line, in the
container's health log (`docker inspect ansilume_app`, `State.Health.Log`),
in `bin/diagnose` under **App health check**, and with
`docker compose exec -u www-data app php yii health/check`:

```
[health] dirs: not writable — /var/www/runtime/logs/app.log (lines logged to it are lost; the next start of the container hands it back to www-data, or run in the container: chown -R www-data:www-data /var/www/runtime/logs)
```

`bin/diagnose` also reports, under **Application Integrity**,
`Log file not writable for www-data: /var/www/runtime/logs/app.log — the errors of the web UI and the API are lost`.
While the app is unhealthy, a `docker compose up -d` that has to create
nginx anew does not start it
(`dependency failed to start: container ansilume_app is unhealthy`).

**Cause:** `www-data`, the user of php-fpm's workers and of the
queue-worker, cannot append to `runtime/logs/app.log`. Yii drops every
line of a process that cannot write its log file, so the errors of the web
UI and the API are lost without a word. A process of another user, mostly
root, created the file after the container started: a console command run
without `-u www-data` that found no log, or the health check of a compose
file of 2.13.1 or older, which runs as root. A root process that rotates a
full log (10 MB) also leaves `app.log.1` to `app.log.5` as root's copies,
whose lines the next rotations lose. The entrypoints run the migrations as
`www-data` and hand `runtime/logs` to `www-data` before the command
starts, and the health checks of the compose files of this release run as
`www-data` and fail while it cannot write the log; before, nothing showed
it.

**Fix:** Hand the log directory back to `www-data`; the next check sees
it:

```bash
docker compose exec app chown -R www-data:www-data /var/www/runtime/logs
```

With the prebuilt images the queue-worker keeps a log of its own:
`docker compose exec queue-worker chown -R www-data:www-data /var/www/runtime/logs`.
Or restart the container (`docker compose restart app`): its entrypoint
does the same when it starts.

Run console commands as `www-data`, as this documentation writes them
(`docker compose exec -u www-data app php yii …`). Do the same in your own
scripts and cron entries, such as the nightly `ldap/sync` of
[ldap.md](ldap.md#console-commands). A compose file of 2.13.1 or older runs
its health checks as root, which see no log that `www-data` cannot write:
take the compose file of this release with the update (see
[Manual update — prebuilt images](updating.md#manual-update--prebuilt-images)).

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

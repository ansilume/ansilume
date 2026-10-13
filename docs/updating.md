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

## After updating from 2.9.0 or older: approvals and workflows

Approval rules are managed by admins, and nobody approves their own
launch, admins included: a schedule now runs as the user who last changed
what or when it runs, and only admins change what a job template with an
approval rule runs, and the steps of a workflow with an approval step or a
job step whose job template has an approval rule.
Approval timeouts are applied on every installation now, every minute. A
workflow whose approval is rejected ends as failed unless the step has an
On Failure branch, runs keep their history when the steps change, and the
extra vars a run is launched with reach every job step. Deleting a job or workflow template cancels what waits for it,
canceling a job closes its approval request, no job of a canceled workflow
run starts any more, and an approval rule that is in use can no longer be
deleted. What to do:

1. Before the update, run the read-only queries under **Check before the
   update** and act on what they list: the first maintenance run after the
   update applies the timeouts of expired approval requests, launches of
   templates whose rule has too few approvers besides the launcher are
   refused, and workflow runs follow new routes.
2. After the update, restart the queue-worker and the schedule-runner once
   the app container has run the migrations, and read what the migrations
   printed (see **Database and deployment**).
3. Give users who are not admins and should keep managing approval rules a
   custom role with `approval-rule.create` and `approval-rule.update` (see
   **Approval rules are managed by admins**).
4. Add approvers to rules that have no approver besides the users who
   launch their jobs (see **Nobody approves their own launch**).
5. Give approval steps, and job steps whose job template has an approval
   rule, an On Failure branch where the workflow should go on after a
   rejection; their Always branch is no longer taken then (see **A closed
   approval gate ends the run as failed**).
6. Tell the systems that launch workflows through a trigger or the API that
   their extra vars now reach every job step, and that a refused launch
   answers `422` (see **Launch extra vars reach every job step**).
7. Check the schedules that the query under **Schedules run as the user who
   last changed what or when they run** lists, and tell the users who
   change other users' schedules that a schedule runs as them afterwards,
   so they no longer approve its jobs.
8. Tell the users who are not admins that only admins change the steps of
   a workflow with a job step whose job template has an approval rule (see
   **Gated job steps are for admins too**).
9. Tell them that only admins change what a job template with an approval
   rule runs, every field but its name and description; review what other
   users set on these templates before they got their rule, and the
   workflows that use them, and decide who may change the inventories,
   projects and credentials they use, and who may delete credentials (see
   **Only admins change what a gated job template runs** and **What an
   approval rule does not stop**).

**Check before the update.** Run these read-only queries with the database
client of the `db` container, or with your own client for an external
database, before you update:

```bash
docker compose exec db sh -c 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

The first lists the pending approval requests whose timeout has passed.
Earlier releases never applied timeouts on the stock deployments; the first
maintenance run after the update applies them all, and `after_the_update`
says what that does. A rule whose timeout action is `approve` approves a
job only within 60 minutes after its request expired, so such a job runs
without a review; cancel the jobs that must not run before you update.

```sql
-- Pending approval requests whose timeout has passed
SELECT r.id, r.job_id, j.status AS job_status, ru.name AS rule,
  ru.timeout_action, FROM_UNIXTIME(r.expires_at) AS expired_at,
  CASE
    WHEN j.status IS NULL OR j.status <> 'pending_approval' THEN 'closed as canceled'
    WHEN ru.timeout_action = 'approve' AND UNIX_TIMESTAMP() - r.expires_at <= 3600 THEN 'approved: the job runs'
    ELSE 'rejected'
  END AS after_the_update
FROM approval_request r
LEFT JOIN job j ON j.id = r.job_id
LEFT JOIN approval_rule ru ON ru.id = r.approval_rule_id
WHERE r.status = 'pending' AND r.expires_at IS NOT NULL
  AND r.expires_at <= UNIX_TIMESTAMP()
ORDER BY r.expires_at, r.id;
```

The second lists the pending requests whose job no longer waits for
approval, mostly of jobs and workflow runs that were canceled. The update
closes them as canceled (see **Canceling closes the approval request**):

```sql
-- Pending approval requests whose job no longer waits for approval
SELECT r.id, r.job_id, j.status AS job_status
FROM approval_request r
JOIN job j ON j.id = r.job_id
WHERE r.status = 'pending' AND j.status <> 'pending_approval'
ORDER BY r.id;
```

The third lists every approval rule with the number of active users on its
approver list and what uses it. Where `active_approvers` is not higher than
`required_approvals`, a launch by one of these approvers is refused, and
where it is lower, every launch is (see **Nobody approves their own
launch**); such a rule's `pending_requests` may never be decided. The query
cannot check `approval.decide` or team access, which count too, so a rule
can have fewer eligible approvers than it shows. A rule with
`job_templates`, `workflow_steps` or `pending_requests` above 0 is in use
and cannot be deleted after the update. A rule whose approver list is
empty or no valid JSON counts no approvers; the query reads such a list as
`{}`, so that MySQL 8 does not stop at it with an error:

```sql
-- Approval rules: active approvers and what uses them
SELECT r.id, r.name, r.approver_type, r.required_approvals,
  (SELECT COUNT(*) FROM user u
    WHERE u.status = 10 AND (
      (r.approver_type = 'users'
        AND (JSON_CONTAINS(JSON_EXTRACT(r.config, '$.user_ids'), CAST(u.id AS CHAR))
          OR JSON_CONTAINS(JSON_EXTRACT(r.config, '$.user_ids'), JSON_QUOTE(CAST(u.id AS CHAR)))))
      OR (r.approver_type = 'role' AND EXISTS (
        SELECT 1 FROM auth_assignment a
        WHERE a.user_id = u.id
          AND a.item_name = JSON_UNQUOTE(JSON_EXTRACT(r.config, '$.role'))))
      OR (r.approver_type = 'team' AND EXISTS (
        SELECT 1 FROM team_member m
        WHERE m.user_id = u.id
          AND m.team_id = JSON_UNQUOTE(JSON_EXTRACT(r.config, '$.team_id'))))
    )) AS active_approvers,
  (SELECT COUNT(*) FROM job_template t
    WHERE t.approval_rule_id = r.id AND t.deleted_at IS NULL) AS job_templates,
  (SELECT COUNT(*) FROM workflow_step s
    JOIN workflow_template w ON w.id = s.workflow_template_id
    WHERE s.approval_rule_id = r.id AND w.deleted_at IS NULL) AS workflow_steps,
  (SELECT COUNT(*) FROM approval_request q
    JOIN job j ON j.id = q.job_id
    WHERE q.approval_rule_id = r.id AND q.status = 'pending'
      AND j.status = 'pending_approval') AS pending_requests
FROM (SELECT x.*,
    IF(JSON_VALID(x.approver_config), x.approver_config, '{}') AS config
  FROM approval_rule x) r
ORDER BY r.id;
```

The fourth lists the users who create or change approval rules only as
operators today: through the operator role, or a custom role that includes
it. They lose that with the update (see **Approval rules are managed by
admins**):

```sql
-- Users who create or change approval rules only as operators
SELECT u.id, u.username, a.item_name AS role
FROM user u
JOIN auth_assignment a ON a.user_id = u.id
WHERE (a.item_name = 'operator'
       OR a.item_name IN (SELECT c.parent FROM auth_item_child c
                          WHERE c.child = 'operator'))
  AND u.is_superadmin = 0
  AND NOT EXISTS (
    SELECT 1 FROM auth_assignment x
    WHERE x.user_id = u.id
      AND (x.item_name = 'admin' OR x.item_name IN (
        SELECT g.parent FROM auth_item_child g
        WHERE g.parent <> 'operator'
          AND g.child IN ('approval-rule.create', 'approval-rule.update'))))
ORDER BY u.id;
```

The fifth lists the workflow steps whose route changes: approval steps,
and job steps whose job template has an approval rule, without an On
Failure branch or with an Always branch. After a rejection, a timeout or a
cancel, such a step no longer takes its Always branch, and without an On
Failure branch it ends the run as failed instead of going on (see **A
closed approval gate ends the run as failed**):

```sql
-- Approval gates without an On Failure branch or with an Always branch
SELECT w.id AS workflow_id, w.name AS workflow, s.id AS step_id,
  s.step_order, s.name AS step, s.step_type,
  s.on_failure_step_id, s.on_always_step_id
FROM workflow_step s
JOIN workflow_template w ON w.id = s.workflow_template_id
LEFT JOIN job_template t ON t.id = s.job_template_id
WHERE w.deleted_at IS NULL
  AND (CAST(s.step_type AS BINARY) = 'approval'
    OR (CAST(s.step_type AS BINARY) = 'job' AND t.approval_rule_id IS NOT NULL))
  AND (s.on_failure_step_id IS NULL OR s.on_always_step_id IS NOT NULL)
ORDER BY w.id, s.step_order, s.id;
```

The sixth lists the branches to steps that no longer exist, which used to
end a run as succeeded and fail it now (see **Branches to removed steps
fail the run**). Remove such a step and add it again with its routes:

```sql
-- Branches to steps that no longer exist
SELECT w.id AS workflow_id, w.name AS workflow, s.id AS step_id,
  s.name AS step, s.on_success_step_id, s.on_failure_step_id,
  s.on_always_step_id
FROM workflow_step s
JOIN workflow_template w ON w.id = s.workflow_template_id
WHERE w.deleted_at IS NULL AND (
  (s.on_success_step_id IS NOT NULL AND s.on_success_step_id <> 0
    AND NOT EXISTS (SELECT 1 FROM workflow_step x
      WHERE x.id = s.on_success_step_id
        AND x.workflow_template_id = s.workflow_template_id))
  OR (s.on_failure_step_id IS NOT NULL AND s.on_failure_step_id <> 0
    AND NOT EXISTS (SELECT 1 FROM workflow_step x
      WHERE x.id = s.on_failure_step_id
        AND x.workflow_template_id = s.workflow_template_id))
  OR (s.on_always_step_id IS NOT NULL AND s.on_always_step_id <> 0
    AND NOT EXISTS (SELECT 1 FROM workflow_step x
      WHERE x.id = s.on_always_step_id
        AND x.workflow_template_id = s.workflow_template_id))
)
ORDER BY w.id, s.step_order, s.id;
```

The seventh lists the enabled schedules and the waiting jobs of job
templates that were deleted, and the waiting jobs whose job template is
gone, as deleting a project removes its deleted job templates for good
(`job without template`; the job of a workflow approval step has no job
template and is not listed). The update disables the schedules, and the
maintenance run starts canceling the jobs within ten minutes, with a
`job.canceled` notification for each (see **Deleting a job template cancels
its waiting jobs and disables its schedules**):

```sql
-- Enabled schedules and waiting jobs of deleted job templates
SELECT 'schedule' AS kind, s.id, s.name AS detail, s.job_template_id
FROM schedule s
JOIN job_template t ON t.id = s.job_template_id
WHERE s.enabled <> 0 AND t.deleted_at IS NOT NULL
UNION ALL
SELECT 'job', j.id, j.status, j.job_template_id
FROM job j
JOIN job_template t ON t.id = j.job_template_id
WHERE t.deleted_at IS NOT NULL
  AND j.status IN ('pending', 'queued', 'pending_approval')
UNION ALL
SELECT 'job without template', j.id, j.status, j.job_template_id
FROM job j
WHERE j.job_template_id IS NULL
  AND j.status IN ('pending', 'queued', 'pending_approval')
  AND NOT EXISTS (SELECT 1 FROM workflow_job_step e
    JOIN workflow_step ws ON ws.id = e.workflow_step_id
    WHERE e.job_id = j.id AND CAST(ws.step_type AS BINARY) = 'approval')
ORDER BY kind, id;
```

The eighth lists the running workflow runs. The steps of a workflow cannot
change while a run of it is running (see **Workflow steps keep their
history and wait for active runs**). A run with `stranded` 1 has no running
step and will never finish, for example because an earlier release deleted
its step records when the steps changed: cancel it on its page.

```sql
-- Running workflow runs; stranded: no step is running
SELECT r.id, r.workflow_template_id, w.name AS workflow,
  FROM_UNIXTIME(r.started_at) AS started,
  NOT EXISTS (SELECT 1 FROM workflow_job_step e
    WHERE e.workflow_job_id = r.id AND e.status = 'running') AS stranded
FROM workflow_job r
JOIN workflow_template w ON w.id = r.workflow_template_id
WHERE r.status = 'running'
ORDER BY r.id;
```

The ninth lists the approval steps without an approval rule that exists:
earlier releases saved approval steps without a rule, and deleting a rule
left its steps pointing to nothing. Such a workflow ran its first steps and
failed at that step; after the update its launch is refused before the run
exists, by the launch form, the API and its trigger (`422`, see
**Database and deployment**). An admin removes the step and adds it again
with a rule:

```sql
-- Approval steps without an approval rule that exists
SELECT w.id AS workflow_id, w.name AS workflow, s.id AS step_id,
  s.step_order, s.name AS step, s.approval_rule_id
FROM workflow_step s
JOIN workflow_template w ON w.id = s.workflow_template_id
WHERE w.deleted_at IS NULL
  AND CAST(s.step_type AS BINARY) = 'approval'
  AND NOT EXISTS (SELECT 1 FROM approval_rule r WHERE r.id = s.approval_rule_id)
ORDER BY w.id, s.step_order, s.id;
```

The tenth lists the jobs that wait for approval without an approval
request. Earlier releases left them when a rule was deleted while it had
pending requests, which deleted the requests, or when the request was not
created after the job had been saved. Nobody can decide them and they never
time out; the maintenance run cancels them after the update (see **Jobs of
ended workflow runs never start**). `workflow_run` is the workflow run that
waits for such a job:

```sql
-- Jobs that wait for approval without an approval request
SELECT j.id, j.job_template_id, j.launched_by,
  FROM_UNIXTIME(j.created_at) AS launched,
  (SELECT e.workflow_job_id FROM workflow_job_step e
    WHERE e.job_id = j.id LIMIT 1) AS workflow_run
FROM job j
WHERE j.status = 'pending_approval'
  AND NOT EXISTS (SELECT 1 FROM approval_request r WHERE r.job_id = j.id)
ORDER BY j.id;
```

The eleventh lists the approval rules whose approver list an older version
stored as a JSON list or a single value instead of an object. Such a rule
names no approvers, so every launch it gates is refused after the update,
and the API returns its `approver_config` as `{}`. Until it gets a JSON
object, `PUT /api/v1/approval-rules/{id}` answers `422`
`Approver Config must be a JSON object.` for it, also when the body leaves
`approver_config` out. An admin saves the rule in its web form, which
stores the approvers the form shows, or sends an `approver_config` object
with `PUT`:

```sql
-- Approval rules whose approver list is no JSON object
SELECT id, name, approver_type, approver_config
FROM approval_rule
WHERE CASE WHEN JSON_VALID(approver_config)
  THEN JSON_TYPE(approver_config) <> 'OBJECT' ELSE 0 END
ORDER BY id;
```

**Approval rules are managed by admins.** Whoever may change a rule's
approvers can open every gate that uses it, so creating and changing rules
is for admins now: migration `m000082_000000_restrict_approval_rule_management`
takes `approval-rule.create` and `approval-rule.update` from the operator
role, and so from custom roles that include it. Deleting rules was for
admins already. Operators keep viewing rules and deciding requests. To let
other users manage rules, give them a custom role with both permissions.
Choosing which rule gates what is for the same admins, users with
`approval-rule.update` (and superadmins):

- Setting, changing or removing the approval rule of a job template. The
  job template form offers the rule only to them; everyone else sees it as
  text with `Only admins may assign or remove an approval rule: that needs approval-rule.update.`,
  and a forged form or `POST`/`PUT /api/v1/job-templates` with another
  `approval_rule_id` is refused with that message (`403`) and saves
  nothing. Sending the stored rule again, or leaving the field out, is
  allowed. A clone keeps the rule of the template it copies, for everyone:
  copying is no assigning, and a clone without the rule would be an ungated
  twin. The template page shows the rule to everyone who may see the
  template, and `job-template.created`, `job-template.updated` and the
  clone's audit entry record `approval_rule` with `from` and `to` when the
  rule changes.
- Adding an approval step to a workflow, and adding, removing, moving or
  replacing any step of a workflow that has one (see **Approval steps are
  for admins**).
- Changing what a job template with an approval rule runs, which would
  change what its rule gates: every field but its name and description
  (see **Only admins change what a gated job template runs**).

Rule audit entries now record the source (`web` or `api`) and the settings;
`approval-rule.updated` records each changed field as `from` and `to` under
`changes`, and `approval-rule.deleted` the job templates and workflow steps
the delete detached. A rule's creator cannot be changed by a form any more.

**Nobody approves their own launch.** The user who launched a job never
decides on its approval request, admins and superadmins included: their
votes are refused with
`You launched this job; another approver must decide.` (`403`), the request
page shows them why instead of Approve and Reject, and votes they cast
before the update count neither as approvals nor as rejections (the page
marks them `launcher, not counted`; `approval_count` and `rejection_count`
of the API leave them out). The launcher is the user the job was launched
as: whoever launched it in the web UI or the API, the user a schedule runs
as (see **Schedules run as the user who last changed what or when they
run**), the user a trigger runs as, the user who started a workflow run
(for its approval steps and job steps), and the user who re-launched a job.
A trigger runs as the user who generated its token; a token generated
before Ansilume recorded that user runs as the template's creator, who
therefore counts as the launcher of its jobs, and never approves them,
until the token is generated again (see
[Review trigger tokens, schedules and workflow steps](#after-updating-from-280-or-older-team-scoping-for-workflows-approvals-and-triggers)
in the notes of 2.8.1).

An eligible approver is on the rule's approver list (a role rule counts the
users assigned that role directly), has an active account, is a superadmin
or holds `approval.decide`, may see the request (team scoping), and is not
the launcher. Disabled users, users without `approval.decide`, such as
viewers, and users who may not see the request used to count toward a
rule's threshold; they no longer do. Console commands, which apply the
timeouts, now give every user the RBAC default role `viewer`, as the web UI
always did, so both count the same users.

Requests that are pending at the update are decided by the same rules. One
whose remaining approvers are only its launcher, disabled users, users
without `approval.decide` or users who may not see it can no longer be
decided: it holds its job, and a workflow run that waits for it, whose
steps cannot change meanwhile. Add an approver to its rule, as who may
decide is checked at every vote, or cancel its job; a rule's timeout still
resolves it. The third query under **Check before the update** shows the
rules with such requests.

A launch is refused before anything is written when fewer eligible
approvers than the rule requires remain besides the launcher, as such a
request could never be approved:
`Approval rule "deploy-prod" needs 1 approval(s) from users other than the one who launches, but only 0 of its approvers may decide on this job. An admin can add approvers to the rule.`
The launch form shows it after `Launch failed: `, `POST /api/v1/jobs` and
job triggers answer `422`, a scheduled run fails to launch with it, and a
workflow job step fails with it. A workflow launch checks every approval
step and every job step whose job template has a rule, in step order, and
is refused (launch form, `422` from the API and triggers) with
`Step "Release approval": approval rule "deploy-prod" needs 1 approval(s) from users other than the one who launches, but only 0 of its approvers may decide. An admin can add approvers to the rule.`,
or for an approval step whose rule no longer exists with
`Step "Release approval": its approval rule no longer exists. An admin can choose another rule.`
An approval step that a run reaches with too few eligible approvers, for
example because one was disabled meanwhile, fails without a request:
`Not run: approval rule "deploy-prod" needs 1 approval(s) from users other than user #7, who launched this workflow, but only 0 of its approvers may decide.`
The approval rule form notes that the launcher never approves when a rule
requires as many approvals as it has approvers. A rule's timeout is no
vote: a rule whose timeout action is `approve` still runs the launcher's
job when nobody decides in time.

**Schedules run as the user who last changed what or when they run.** A
schedule launches its jobs as one user, the launcher of these jobs, who
never approves them. Until now that was always the schedule's creator,
also after other users had changed the schedule: an operator could point
an admin's schedule at a job template with an approval rule, with extra
vars and a time of the operator's choosing, and then approve alone the job
that the schedule launched as the admin. Now a schedule runs as the user
who last saved a change of what or when it runs, or enabled it:

- a change of its job template, cron expression, timezone or extra vars,
  in the schedule form or with `PUT /api/v1/schedules/{id}`; a password
  answer entered anew or removed counts, `$encrypted$` sent back for a
  stored answer does not, and neither do extra vars that write the same
  values differently, such as with other spaces or line breaks;
- enabling a disabled schedule, with **Enable** on the schedule list or
  page, with the form's Enabled box, with `PUT` and `"enabled": true`, or
  with `POST /api/v1/schedules/{id}/toggle`.

Renaming a schedule, disabling it, and a save that changes none of these
keep the user it runs as. The schedule page and the schedule list show
that user as **Runs as**, the page with the note
`Scheduled jobs run as this user, and only while they may launch the job template. Whoever saves a change to the job template, cron expression, timezone or extra vars, or enables the schedule, becomes the user it runs as.`,
and the edit form of a schedule that runs as someone else says
`Saving a change to the job template, cron expression, timezone or extra vars, or enabling the schedule, makes it run as you.`;
the API keeps the field `created_by` for it. The `schedule.updated` and
`schedule.toggled` audit entries record `run_as` with `from` and `to` when
the user changes. This replaces the rule of 2.8.1 that editing a schedule
keeps its creator (see
[After updating from 2.8.0 or older](#after-updating-from-280-or-older-team-scoping-for-workflows-approvals-and-triggers)):
a schedule whose user may no longer launch its job template starts again
once a user who may launch it saves such a change, or disables and enables
it; it no longer has to be recreated. After someone else changed your
schedule, its jobs are theirs: they can no longer approve them, and you
can.

The update changes no schedule's user. A schedule that another user
changed before the update still runs as the user it ran as, so the user
who changed it may still approve its jobs, until someone saves such a
change or enables it again. This read-only query, before or after the
update, lists the schedules of job templates with an approval rule that
users other than the one they run as changed or toggled, with these users
(`changed_by`). Check the job template, extra vars and times of each on its
page; whoever changes one of them, or disables and enables the schedule,
becomes the user it runs as:

```sql
-- Schedules of gated job templates that users other than the one they
-- run as changed or toggled
SELECT * FROM (
  SELECT s.id, s.name, s.enabled, s.job_template_id,
    s.created_by AS runs_as,
    (SELECT GROUP_CONCAT(DISTINCT a.user_id ORDER BY a.user_id)
       FROM audit_log a
      WHERE a.action IN ('schedule.updated', 'schedule.toggled')
        AND a.object_type = 'schedule' AND a.object_id = s.id
        AND a.user_id <> s.created_by) AS changed_by
  FROM schedule s
  JOIN job_template t ON t.id = s.job_template_id
  WHERE t.approval_rule_id IS NOT NULL AND t.deleted_at IS NULL
) r
WHERE r.changed_by IS NOT NULL
ORDER BY r.id;
```

**Only admins change what a gated job template runs.** A gated job
template is a job template with an approval rule. A schedule, a trigger or
a workflow launches its jobs as the user it runs as, who never approves
them. So a user who changed another user's gated job template, its
playbook or extra vars for example, chose what that user's schedule,
trigger or workflow launched next, and could approve it alone. Now only
users with `approval-rule.update` (admins and superadmins) change what a
gated job template runs: every field but its name and description, that
is its project, inventory, playbook, credentials and their order, extra
vars, survey, runner group, timeout and Ansible settings such as limit,
tags, verbosity, forks and become. Everyone else gets `403` with
`This job template has an approval rule: only admins may change what it runs (approval-rule.update). You may change its name and description.`,
in the web UI and from `PUT /api/v1/job-templates/{id}`, and nothing is
saved. A field sent with the value it has is no change. Values are
compared as their field's type (`"5"` and `5` are the same), the extra vars
and the survey as JSON, where spacing does not count, but another order of
keys does, and the credentials as the list in its order. So a client that
sends back what `GET /api/v1/job-templates/{id}` returned, with a new name,
is not refused. A request that also changes the approval rule is refused
for that first, with the message under **Approval rules are managed by
admins**. The save locks the template and decides in the same transaction,
so a rule that an admin assigns at the same moment counts. The template
page and its edit form show users who may change the template, but not
what it runs,
`This job template has an approval rule: only admins may change what it runs (approval-rule.update). You may still change its name and description, launch it and manage its trigger token.`,
and the form disables every field but the name and the description, the
credentials, the extra vars and the survey editor included; saving it
changes only these two. They still launch the template, generate and
revoke its trigger token and clone it, and delete it where they hold
`job-template.delete`, which the operator role does not grant. A clone
keeps the rule, so they may rename the clone, but not change what it runs.
Admins see no notice and change everything as before.

Assigning a rule leaves alone what was set before. When an admin gives a
job template an approval rule, its playbook, extra vars, survey, inventory
and credentials stay as whoever changed them last left them, and so do the
job steps that workflows got for the template while it had no rule. From
then on, users without `approval-rule.update` change neither the template
nor the steps of these workflows (see **Gated job steps are for admins
too**), but the job of such a step runs as the user who starts the run, or
as the user its trigger runs as, and the user who added the step may
approve it. So review the template and its workflows when you assign a
rule. Who changed the template is in its `job-template.updated` audit
entries (see the query under **What an approval rule does not stop**).
This read-only query, before or after the update, lists the job steps of
live workflows whose job template has an approval rule, with the user the
workflow's trigger runs as (`trigger_runs_as`, empty without a token) and
the users of the workflow's `workflow-template.created`,
`workflow-template.updated` and `workflow-template.step.added` entries
(`changed_by`), among them whoever added the step. Remove a step that
should not be there:

```sql
-- Job steps of live workflows whose job template has an approval rule
SELECT w.id AS workflow_id, w.name AS workflow, s.id AS step_id,
  s.name AS step, t.id AS job_template_id, t.name AS job_template,
  t.approval_rule_id,
  IF(w.trigger_token IS NULL, NULL,
    COALESCE(w.trigger_token_created_by, w.created_by)) AS trigger_runs_as,
  (SELECT GROUP_CONCAT(DISTINCT a.user_id ORDER BY a.user_id)
     FROM audit_log a
    WHERE a.action IN ('workflow-template.created',
                       'workflow-template.updated',
                       'workflow-template.step.added')
      AND a.object_type = 'workflow_template'
      AND a.object_id = w.id) AS changed_by
FROM workflow_step s
JOIN workflow_template w ON w.id = s.workflow_template_id
JOIN job_template t ON t.id = s.job_template_id
WHERE w.deleted_at IS NULL AND t.deleted_at IS NULL
  AND t.approval_rule_id IS NOT NULL
  AND CAST(s.step_type AS BINARY) = 'job'
ORDER BY w.id, s.step_order, s.id;
```

**What an approval rule does not stop.** An approval rule gates a job
template, not a playbook or an inventory. A user who may create job
templates in a project, as operators may, can create a template without a
rule that runs the same playbook on the same inventory, and launch it
without an approval; team scoping limits this to the projects the user may
operate. Only admins assign and remove rules, but they cannot stop such a
twin. An approval step gates the runs of its workflow, not the job
templates in it: users who may launch those templates can launch them
directly, or from another workflow without the step. To gate a job template
wherever it runs, give the template an approval rule. An approval approves
a launch, not what the job reads later: a runner gets the playbook from the
project's repository, and the job's inventory, when it claims the job,
after the approval, so whoever may change the inventory, or push to the
repository, can change what an approved job does. An approval at the level
of an inventory is planned.

Only admins change a gated job template itself (see **Only admins change
what a gated job template runs**), but not what it refers to: its
inventory, its project and its credentials keep their own permissions. By
default every operator holds them: `inventory.update` and `project.update`
for the projects they may operate and their inventories (with teams, those
where they have operator access; an inventory without a project for all of
them), and `credential.update` for every credential. Users who may change
these change what the template's next jobs run, such as the hosts, the
repository and branch, or a secret, and so do users who may push to the
repository, and users who delete a credential that the template uses:
deleting a credential that is in use, with **Delete anyway** on its page or
`DELETE /api/v1/credentials/{id}?force=1`, detaches it from every job
template that uses it, gated ones included, whose next jobs then run
without it; that needs `credential.delete`, which only admins hold by
default. Such a change does not make them the launcher of these jobs,
so they may approve them when they are on the rule's approver list. This
stays so until approvals at the level of an inventory come. Until then,
choose the approvers of a rule among admins and users who may not change
the inventory, project and credentials of its templates, such as users of
a custom role that holds `approval.decide` but no update permission; keep
`credential.delete` with the admins; keep the projects of gated job
templates in teams with few operators; and check what changed before you
approve. This read-only query lists the changes of the last 30 days to job
templates with an approval rule and to the inventories, projects and
credentials they use, newest first, with the user who made them. A forced
delete of a credential is not among them: its
`credential.deleted` audit entry records `forced` and the number of job
templates it was detached from (`job_templates`), not which. Pushes to a
repository happen outside Ansilume and are not in its audit log:

```sql
-- Changes of the last 30 days to gated job templates and to the
-- inventories, projects and credentials they use, newest first
SELECT a.id, FROM_UNIXTIME(a.created_at) AS changed_at, a.user_id,
  a.action, a.object_id, t.id AS job_template_id, t.name AS job_template
FROM audit_log a
JOIN job_template t
  ON t.approval_rule_id IS NOT NULL AND t.deleted_at IS NULL
  AND ((a.object_type = 'job_template' AND a.object_id = t.id)
    OR (a.object_type = 'project' AND a.object_id = t.project_id)
    OR (a.object_type = 'inventory' AND a.object_id = t.inventory_id)
    OR (a.object_type = 'credential' AND (a.object_id = t.credential_id
      OR a.object_id IN (SELECT c.credential_id
                           FROM job_template_credential c
                          WHERE c.job_template_id = t.id))))
WHERE a.action IN ('job-template.updated', 'project.updated',
                   'inventory.updated', 'credential.updated')
  AND a.created_at >= UNIX_TIMESTAMP() - 30 * 86400
ORDER BY a.id DESC;
```

**Votes are checked under a lock.** A vote locks its request and checks, in
this order, that the request is still pending, that it has not expired,
that its job still waits for approval, that the voter did not launch the
job, that the voter is an eligible approver, and that the voter has not
voted yet. Votes and timeouts of one request run one after the other, and
the first that decides it wins, so a request is decided exactly once. A
vote that loses a race, or comes late, is refused and records nothing:

- `Approval request #12 is already resolved: approved.` (or `rejected`,
  `timed out`, `canceled`): `409` with `error.status`;
- `Approval request #12 has expired and takes no more votes; its timeout is applied by the next maintenance run.`:
  `409`; a vote at or after `expires_at` no longer counts;
- `Approval request #12 can no longer be decided: its job #34 no longer waits for approval.`:
  `409`, and the request is closed as canceled; also when the job still
  waits but can never run: its workflow step no longer runs, or its run has
  ended, for example because canceling the run failed part way, or its job
  template was deleted. Then the job is canceled too (see **Canceling
  closes the approval request**);
- `You launched this job; another approver must decide.`: `403`;
- `You are not eligible to decide on this request.`: `403`, no longer for
  a resolved request;
- `User has already voted on this request.`: `422`, also for a second vote
  sent at the same moment;
- `The decision cannot be recorded: Comment should contain at most 1,000 characters.`:
  `422`, which replaces `Failed to save approval decision: ...`; a comment
  that is not a string, which answered `500` before, gets
  `The decision cannot be recorded: Comment must be a string.`

The web UI shows a refused vote as a warning (`409`), as an error page
(`403`), or as `Your decision was not recorded: ...` (`422`). A vote that
was recorded is answered as recorded, also when an effect after it, such as
a notification or the next workflow step, fails; the application log then
has `Approval request #12: the audit entry, notification or workflow advance after the vote of user #7 failed: ...`,
without values. The
request page names the user who launched the job (**Launched by**), says
when the request was resolved, explains why it offers no Approve or Reject
to a user who holds `approval.decide`, takes a comment with a rejection,
and shows a deleted rule as `deleted rule`.

**Approval timeouts run every minute.** The maintenance run of the
schedule-runner (`php yii maintenance/run`) now applies the timeouts of
expired approval requests, at most 100 per minute, the longest expired
first, and prints `[maintenance] ran: approval-timeouts (resolved=N)` when it
resolved some. It waits while database migrations are pending. The
timeouts and the sweep of deleted templates below share a time budget of
45 seconds per run: once it is used up, for example because a notification
endpoint answers slowly, the run starts no further timeout or cancel, so
that it does not hold up the schedules, and the rest follows at the next
run. Until now nothing ran `php yii approval/check-timeouts` on the stock
deployments, so no timeout ever applied; that command stays for
installations with their own cron setup, applies every expired request at
once, and prints
`Approval timeouts not applied: N database migration(s) pending; they are applied once the update has finished.`
during an update. A timeout whose action is `approve` approves only within
60 minutes after the request expired, counted when the timeout is applied;
a timeout applied later rejects, so that nothing runs that nobody reviewed
in time. The `approval.timed_out` audit entry records `applied_action`, and
`reason: grace_period_exceeded` when an approve came too late and rejected
instead.

When applying a timeout fails, nothing of it is kept: the application log
has `Approval timeout of request #12 failed: ...` with the class of the
error, and the next run tries again, before the other requests. When the
timeout was applied but what follows it failed, such as its audit entry, a
notification or the next step of a workflow, the log has
`Approval request #12 was settled by its timeout, but the audit entry, notification or workflow advance after it failed: ...`
instead. That is not tried again: a workflow run that waited for the
request stays at its step; cancel it and launch it again (see
[Approval timeouts or the deleted-template sweep keep failing](troubleshooting.md#approval-timeouts-or-the-deleted-template-sweep-keep-failing)).

**Canceling closes the approval request.** Approval requests have a new
status, `canceled` ("Canceled"). Canceling a job that waits for approval,
canceling a workflow run that waits at an approval step or at a job step
whose job waits for approval, and deleting the job template or the workflow
template of such a job, cancel its pending request in the same transaction;
it takes no more votes. Until now the request stayed pending, could still
be decided, and stayed on the dashboard and in the pending list. Each such
request gets an `approval.canceled` audit entry with `job_id`, `rule_id`
and `reason`: `operator`, `template_deleted`, `workflow_canceled` or
`workflow_template_deleted`, as the `job.canceled` entry of its job. A vote
or timeout that finds a pending request whose job no longer waits closes it
the same way, with `reason: job_not_waiting`, and the voter, or nobody for a
timeout, as the user. One whose job still waits for approval but can never
run is closed, and the job canceled, with the same user, as the cancel the
vote or timeout stands in for would have done it:

- the job's workflow step no longer runs, or its run has ended, whatever
  became of its job template: the request gets
  `reason: workflow_step_ended`, and the job a `job.canceled` entry with
  `workflow_job_id` and the reason the run was canceled for (see **Jobs of
  ended workflow runs never start**);
- the job's job template was deleted, and the vote or timeout came before
  the delete's own cancel of the job, or that cancel failed: the request
  and the job get `reason: template_deleted`, the job its `job.canceled`
  notification, and a workflow step that waits for the job fails with it.
  The same holds for a job whose job template is gone, removed with its
  project, unless it is the job of a workflow approval step, which has no
  job template by design.

Migration `m000085_000000_close_orphaned_approval_requests` closes the
pending requests whose job no longer waits for approval that earlier
releases left. The Approvals tab of Analytics counts canceled requests in
their own card. The average decision time leaves them out, and
counts a timed-out request until it expired, also when its timeout was
applied later, as the first maintenance runs after the update do for the
requests that expired before.

A cancel changes a job only while it has not finished. When the job
finished between the click and the cancel, the web UI shows the warning
`Job #42 was not canceled: it is already succeeded.` (with the status in
words) and `POST /api/v1/jobs/{id}/cancel` answers `409` with that message
and the job's status in `error.status`; before, the cancel overwrote the
outcome.

**Deleting a job template cancels its waiting jobs and disables its
schedules.** A deleted template can never run them: its queued jobs used to
stay queued for good, and its schedules failed at every due time. Deleting a
template now disables its enabled schedules and cancels its jobs that wait:
`pending`, `queued` and `pending_approval`, each with a `job.canceled` audit
entry (`reason: template_deleted`) and notification, and with its pending
approval request. A workflow step that waits for such a job fails with it.
Running jobs finish. The confirmation says so, the web UI reports
`Template "Deploy web" deleted: 2 waiting job(s) canceled, 1 schedule(s) disabled.`,
`DELETE /api/v1/job-templates/{id}` answers with `canceled_jobs` and
`disabled_schedules`, and the `job-template.deleted` audit entry records
both counts and the source. Each schedule it disables gets a
`schedule.toggled` entry with `reason: template_deleted`, with no user when
the system disabled it. A job whose cancel fails, for example on a database
error, keeps waiting and is not counted in `canceled_jobs`: the application
log names it, and the maintenance run cancels it (see
[Approval timeouts or the deleted-template sweep keep failing](troubleshooting.md#approval-timeouts-or-the-deleted-template-sweep-keep-failing)).
No runner claims it meanwhile, as runners never claim jobs of deleted
templates, and a vote or a timeout on its approval request cancels it (see
**Canceling closes the approval request**).

- Enabling a schedule whose job template was deleted is refused:
  `Schedule "Nightly deploy" was not enabled: its job template #15 was deleted. Edit the schedule to choose another job template, or delete it.`
  (`422` from `POST /api/v1/schedules/{id}/toggle`).
- A due schedule of a deleted template disables itself and reports once
  `Job template #15 was deleted; the schedule was disabled.` in the
  `schedule.failed_to_launch` notification, instead of
  `Job template not found for schedule #N` at every due time.
- A launch of the template at the moment it is deleted is refused with
  `Job template #15 was deleted.`
- Deleting a project removes its deleted job templates for good, which
  took their jobs off them: such a job stayed queued or waiting for
  approval, no runner took it, and an approval could still be granted that
  never ran it. Now the project's delete first cancels these waiting jobs
  as the delete of their template does, with `reason: template_deleted`
  and their pending approval requests; running jobs finish. A waiting job
  that an earlier release left without its job template can never run: a
  vote or a timeout on its approval request cancels it (see **Canceling
  closes the approval request**), and the sweep below cancels it once it
  is five minutes old. The job of a workflow approval step has no job
  template by design and is not one of them.
- The maintenance run sweeps deleted job and workflow templates every ten
  minutes, for templates deleted before the update, or by a delete that
  stopped half way: it disables their schedules, cancels up to 100 of their
  waiting jobs and up to 100 of their running workflow runs per run, the
  oldest first, and, in the same sweep, up to 100 jobs that ended workflow
  runs left waiting (see **Jobs of ended workflow runs never start**) or
  that lost their job template with its project. It goes on at the next
  run while some remain, also when the run's time budget was used up (see
  **Approval timeouts run every minute**). It prints
  `[maintenance] ran: deleted-template-sweep (canceled_jobs=N, disabled_schedules=N, remaining_jobs=N, canceled_runs=N, failed_runs=N, remaining_runs=N, stranded_jobs_canceled=N, stranded_jobs_failed=N, stranded_jobs_remaining=N)`.
  When the approval timeouts used up the time budget, a sweep that is due
  starts no cancel, and its line shows zero cancels next to what remains,
  for example `canceled_jobs=0` and `remaining_jobs=3`; the next run goes
  on.
  The first runs after the update can cancel old jobs that waited for good,
  and send their notifications (see **Check before the update**). A job or
  run whose cancel failed is tried again at the next run; an error that
  stops the whole sweep, such as a lost database connection, waits for its
  next turn, ten minutes later.

**Deleting a workflow template cancels its running runs.** Their running
steps fail with
`Not finished: the run was canceled because its workflow template was deleted.`,
the jobs of these steps are canceled together with their pending approval
requests, and each run gets one `workflow.canceled` audit entry and
notification. The confirmation names the running runs, the web UI reports
`Workflow template "Release web" deleted. 1 running run(s) were canceled.`,
and `DELETE /api/v1/workflow-templates/{id}` answers with `canceled_runs`
and `failed_runs`. A run whose cancel fails does not stop the others: the
web UI warns
`Workflow template "Release web" deleted. 1 running run(s) were canceled; 1 could not be canceled, the maintenance run retries them (see the application log).`,
and the maintenance run cancels it later, or, when it shows canceled
already, the jobs it left (see **Jobs of ended workflow runs never
start**). The `workflow-template.deleted` audit entry now has the user who
deleted it and records `canceled_runs`, `failed_runs` and the source.

**Jobs of ended workflow runs never start.** Canceling a workflow run, or
deleting its workflow template, cancels the jobs of its running steps one
after the other. When one of these cancels failed, for example on a
database error, the run was canceled all the same, but its job stayed
queued, and a runner ran it, or stayed waiting for approval, and an
approval queued it. Now:

- the cancel goes on with the other steps, and the run gets its
  `workflow.canceled` audit entry and notification all the same; the
  application log names the step whose job was not canceled;
- runners never claim a job of a workflow run that is no longer running;
- a vote or timeout on the approval request of such a job closes the
  request and cancels the job (see **Votes are checked under a lock**);
- the maintenance run cancels such jobs with the sweep of deleted templates
  (`stranded_jobs_canceled`), each with a `job.canceled` audit entry with
  the reason the run was canceled for, and with no user:
  `workflow_template_deleted` when the run was canceled because its
  workflow template was deleted (its newest `workflow.canceled` entry says
  so, or, without such an entry, the template is deleted), otherwise
  `workflow_canceled`. A run that a user canceled before its template was
  deleted keeps `workflow_canceled`. The sweep also ends the steps that
  still show as running in ended runs, with the message of the run's
  cancel, or `Not finished: the run had ended.` for a run that was not
  canceled.

The same sweep cancels the jobs that have waited for approval for five
minutes or more without an approval request, which earlier releases left
(the tenth query under **Check before the update**): `job.canceled` with
`reason: approval_request_missing`, and its notification. A workflow step
that waits for such a job fails as after a rejection, with
`Not run: the approval request of its job no longer exists.`, or
`Not approved: the approval request of this step no longer exists.` for an
approval step: only an On Failure branch that names a step continues the
run (see **A closed approval gate ends the run as failed**).

A step that starts at the moment its run is canceled is canceled with the
reason and the user of that cancel: after a template delete, its error is
`Not finished: the run was canceled because its workflow template was deleted.`,
and its job's `job.canceled` entry names the user who deleted the
template. A database error while a job step launches its job fails the step
with `The job could not be launched; see the application log.`, and one
while a step's record is written ends the run as failed: the run no longer
stays running for good, which locked the steps of its workflow.

**Approval rules in use cannot be deleted.** A rule is in use while live
job templates, steps of live workflows, or pending requests whose job still
waits for approval refer to it. Deleting such a rule silently removed it
from its job templates, which then ran without an approval, deleted its
requests with their votes, and left its workflow steps to fail. Now the
delete is refused, in the web UI and with `409` from
`DELETE /api/v1/approval-rules/{id}`, which names the uses in
`error.used_by`:
`Approval rule "deploy-prod" is in use by 2 job template(s) and 1 workflow step(s). Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.`
There is no force option. The rule page has a **Used by** card and offers
Delete only for an unused rule. Deleting an unused rule removes it from
deleted job templates and from steps of deleted workflows, and keeps the
history of its resolved requests. Migration
`m000083_000000_protect_approval_rule_references` lets the database refuse
such deletes too, and makes `approval_rule_id` of approval requests
nullable (see **Database and deployment**). A rule's name is not unique,
and a change to a rule in use applies to its pending requests at their
next vote or timeout.

**A closed approval gate ends the run as failed.** An approval step that is
rejected, times out with a rejection or is canceled, and a job step whose
job is rejected or canceled while it waits for approval, used to go on with
the next step when its On Failure branch was empty, as if nothing had
happened, and the Always branch of an approval step, or of such a job step,
was followed after a rejection, a timeout or a cancel. Now an empty On
Failure branch ends the run as failed there, the Always branch is not taken
(a job step still takes it after a job that ran), and only an On Failure
branch that names a step continues the run, as the old flow did. The fifth
query under **Check before the update** lists the steps this changes. The
step's error says why:
`Approval request #12 was rejected.`, `Approval request #12 timed out.` or
`Approval request #12 was canceled.` for an approval step, and
`Not run: approval request #12 was rejected.`, `... timed out.` or
`... was canceled.` for a job step. Approval steps cannot have an Always
branch any more: saving one is refused with
`An approval step cannot have an Always branch: a rejection would continue past it.`;
on an approval step saved before, it is followed only after an approval,
and the workflow page marks it `(only on approval)`. Steps written through
`POST` and `PUT /api/v1/workflow-templates` have no branches, so a
rejected approval step written there, or a job step there whose job's
approval is refused, now ends its run as failed. To keep a workflow going
after a rejection, set the step's On Failure branch to the step that should
follow. Other failed steps follow their branches as before. A closed gate
reached by a vote or a timeout now writes the `workflow.step.completed`
audit entry and sends the `workflow.step_failed` notification, as one
reached by a cancel did, and an approval step that is approved writes
`workflow.step.completed` with `status: succeeded`; neither wrote an entry
before.

**A job step whose template needs approval waits for its job.** When the
job template of a workflow job step has an approval rule, the step waits
for the approval and then for its job: an approval queues the job, and the
step ends with the job's outcome. Until now an approval, or a timeout that
approved, marked the step and its job succeeded at once: the job never ran,
and the run went on. These jobs still show Succeeded. This read-only query
lists them, before or after the update; `workflow_job_id` is empty where an
earlier release deleted the step's record when the steps changed:

```sql
-- Jobs of gated workflow job steps that were marked succeeded without running
SELECT j.id AS job_id, j.job_template_id, e.workflow_job_id,
  r.id AS request_id, r.status AS request_status,
  FROM_UNIXTIME(j.finished_at) AS marked_succeeded
FROM job j
JOIN approval_request r ON r.job_id = j.id
  AND r.status IN ('approved', 'timed_out')
LEFT JOIN workflow_job_step e ON e.job_id = j.id
WHERE j.status = 'succeeded' AND j.job_template_id IS NOT NULL
  AND j.started_at IS NULL
ORDER BY j.id;
```

**Workflow steps keep their history and wait for active runs.** Replacing
the steps of a workflow (`PUT /api/v1/workflow-templates/{id}` with
`steps`), or removing a step on its page, deleted the step records of every
earlier and running run, and a running run then stopped for good. Migration
`m000084_000000_keep_workflow_run_history` keeps these records: each one
keeps the name and type the step had when it ran, also after the step is
removed. The run page, its live status and the API show them, the run page
has a Type column, and the API returns `step_name` and `step_type` with a
`workflow_step_id` that is null once the step is gone.

- The steps of a workflow cannot change while a run of it is running: adding,
  removing, moving and replacing steps is refused with
  `Steps cannot change while run(s) #12 of this workflow are active: wait for them to finish or cancel them.`
  (`409` from the API, with every such run in `error.workflow_job_ids`;
  the web UI shows it, after `Step not added: ` for a new step). The
  workflow page names the running runs and hides the step controls while
  there are any. A run reads the steps at every step it reaches, so a
  change could send it elsewhere than it started, or strand it. To find the
  runs that block a workflow, use the eighth query above with
  `AND r.workflow_template_id = <id>`, and cancel a stranded run on its page.
- Removing a step that other steps route to is refused:
  `Step "Build" was not removed: other steps route to it (#10 "Retry"). Remove those steps first, and add them again with other routes if you still need them.`
- Name and description of a workflow can change at any time, and so can its
  trigger token; `PUT` without `steps`, or with `steps: null`, changes no
  steps.

**Approval steps are for admins.** Adding an approval step needs
`approval-rule.update`, and so does every change of the steps of a workflow
that has one: adding, removing, moving or replacing any step. Others get
`403` with `Only admins may add approval steps: that needs approval-rule.update.`
or
`This workflow has an approval step: only admins may change its steps (approval-rule.update).`,
in the web UI and from `POST`/`PUT /api/v1/workflow-templates`, for any
`steps` list on such a workflow, the same steps included. The workflow page
offers the Approval type only to them, and shows everyone else a notice
instead of the step controls of a workflow with an approval step; they may
still rename it, launch it and manage its trigger token. An approval step
gates the runs of its workflow, not the job templates in it (see **What an
approval rule does not stop**). A step's On Failure branch on an approval
step offers "end workflow as failed" as its default. The steps table shows
each step's Always branch in a column of its own,
`end workflow (failed)` for an approval step without an On Failure branch,
and `→ next step; end workflow (failed) after a refused approval` for a job
step whose job template has an approval rule and no On Failure branch: a
job that ran and failed goes on with the next step, and a job that never
ran because its approval was refused ends the run as failed. The Always
branch of such a job step is marked
`(not after a rejected, timed-out or canceled approval)`, and the routing
help of job steps says that only On Failure is followed after one.
The add-step form no longer sends a
branch that the chosen step type hides, such as the Always branch of an
approval step, and it
refuses with `Step not added: ` and
`An approval step needs an approval rule.`,
`The selected approval rule does not exist.`, the Always message above, or
`The selected step is not part of this workflow.` for a branch to a step of
another workflow.

**Gated job steps are for admins too.** A gated job step is a job step whose
job template has an approval rule. A workflow run launches its jobs as the
user who started it, or as the user its trigger runs as, and that user never
approves them. So a user who added a gated job step to another user's
workflow chose a job that ran as that user, through the workflow's trigger
or its next run, and could approve it alone. Now a gated job step counts
like an approval step: adding one needs `approval-rule.update`, and so does
every change of the steps of a workflow that has one: adding, removing,
moving or replacing any step. Others get `403` with
`Only admins may add a job step whose job template has an approval rule: that needs approval-rule.update.`
or
`This workflow has a job step whose job template has an approval rule: only admins may change its steps (approval-rule.update).`,
in the web UI and from `POST`/`PUT /api/v1/workflow-templates`, for any
`steps` list on such a workflow, the same steps included. The add-step form
offers them only job templates without an approval rule, and a workflow
with a gated job step shows them the notice instead of the step controls;
they may still rename it, launch it and manage its trigger token. Whether a
job step is gated is read from its job template whenever the steps change:
a job template that gets an approval rule later locks the steps of the
workflows that use it.

**The add-step form adds pause and approval steps again.** Since 2.8.1 the
add-step form could not add a pause or an approval step: the browser checked
the hidden job template field, which those steps leave empty, and Add Step
did nothing, without an error you could see. The fields of the step types
you did not choose are now disabled as well as hidden, so the browser
neither checks nor sends them. The API was not affected.

**Branches to removed steps fail the run.** A branch (On Success, On
Failure or Always) to a step that no longer exists ended the run as
succeeded, as if the workflow were done. Now it fails the run, and the step
whose branch it was gets `The next step #7 of this workflow no longer exists.`
added to its error, also when the step itself succeeded. The sixth query
above lists such branches.

**Launch extra vars reach every job step.** The extra vars a workflow run
is launched with, by a trigger (`/trigger/fire-workflow`) or by
`POST /api/v1/workflow-templates/{id}/launch`, which takes `extra_vars`
now, are stored on the run and reach every job step of it, not only the
start step. A job step gets its job template's extra vars, then the run's
launch extra vars over them, then its own mapping of the previous step's
output over both. A value whose key is a password field of the job template
of any job step is a password answer: the run stores it only encrypted and
shows `$encrypted$` (the run page and `extra_vars` of
`GET /api/v1/workflow-jobs/{id}`), and every step's job gets it encrypted,
also a job of a template without that field; its `job.launched` audit entry
names the run (`workflow_job_id`) and the keys of these answers
(`carried_secret_keys`), never their values. This changes what a playbook of
a later step receives: callers that relied on the vars reaching the start
step only must check their workflows.

A workflow launch is now checked before its run exists, and refused with
`422` (web UI: `Launch failed: ...`) instead of a run that fails at its
start step:

- extra vars that are not a JSON object:
  `The extra vars must be a JSON object, such as {"env": "prod"}.`; `null`,
  an empty text and `{}` mean none;
- extra vars that JSON cannot hold, as for jobs (`The extra vars cannot be stored: ...`,
  `The password answers cannot be stored: ...`), or more than a run stores:
  `The extra vars are too large: a run stores at most 65535 bytes of them as JSON.`;
- `$encrypted$` as a password answer of any job step's template:
  `The value of "db_password" is the placeholder $encrypted$; send the real value.`;
- a workflow without steps: `Workflow template #5 has no steps.`, which a
  trigger answered with `500` `Launch failed.` before;
- a workflow deleted while it was being launched:
  `Workflow template #5 was deleted.`;
- an approval that could never be granted (see **Nobody approves their own
  launch**).

`/trigger/fire-workflow` used to answer `201` for most of these and start
the run anyway.

**Runner reports after a cancel are ignored.** A report that arrives after
the job finished, such as a completion for a job that was canceled while
the runner ran it, no longer overwrites the job, also when the cancel or a
reclaim committed while the runner's report was on its way. The job keeps
its status, its log gets
`Runner reported completion (exit_code=0) after job was already in terminal status "canceled" — ignored.`
or `Runner reported timeout after job was already in terminal status "canceled" — ignored.`,
and no `job.finished` audit entry, webhook, notification or workflow
advance follows. A failure before execution writes nothing then.
`POST /api/runner/v1/jobs/{id}/complete` answers with the status the job
has after the report, `canceled` in this case. Runners need no update.

**Known limits.** These stay as they are in this release:

- The runner is not told about a cancel: it runs the playbook of a canceled
  job to its end, and its report is ignored.
- A job that `php yii job/reclaim-stale` marks failed because its runner
  stopped reporting does not move its workflow run on; cancel the run.
- Re-launch is in the web UI only; the API has no endpoint for it.
- A request whose timeout keeps failing is retried first at every
  maintenance run, and logged each time.
- A workflow run whose next step could not start after a vote or a
  timeout, because what follows the decision failed (logged), stays at its
  step: nothing tries again. Cancel the run and launch it again.
- Saving a schedule checks its job template without waiting for a delete of
  the template that runs at the same moment; such a schedule never launches
  and is disabled at its next due time or by the next sweep.
- Deleting a job template cancels all of its waiting jobs during the
  request; with very many, the request can run into the web server's
  timeout, and the maintenance run cancels the rest.
- `POST /api/v1/workflow-jobs/{id}/cancel` of a run that has finished,
  also while it was being canceled, answers `422`
  `Workflow is already finished.`, and the web UI shows it as an error.
- Users who may change the inventory, the project or the credentials of a
  gated job template, or push to its repository, change what its next jobs
  run without becoming their launcher, and may approve them (see **What an
  approval rule does not stop**). So may users who delete one of its
  credentials with force (`credential.delete`, admins by default), which
  detaches it from the template.
- A job step that a workflow got for a job template before the template
  got its approval rule stays: its job runs as the user who starts the
  run, and the user who added the step may approve it (see **Only admins
  change what a gated job template runs**).
- A migration that fails can print its database error with the message,
  and so with the SQL statement and its values, and its stack trace to
  the output of the app container (`docker compose logs app`): the
  framework's migration runner prints it there, not to the application
  log, which leaves such a message out. Read that output before you share
  it, also as part of what `bin/diagnose` prints, which includes the last
  lines of each container's output; recreating the container drops its
  Docker log, as **Purge old logs** in
  [the notes of 2.8.1](#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets)
  describes.

**Fixes that change behaviour:**

- A launch of a job template whose approval rule no longer exists is
  refused rather than run without the approval:
  `The approval rule of job template #7 no longer exists. An admin can choose another rule.`
  A launch whose template's rule changed while the job was being launched
  is refused with
  `The approval rule of job template #7 changed while the job was being launched. Launch it again.`
- A launch with an approval writes the job and its approval request in one
  transaction, so a job never waits for a request that was not created.
- A launch whose audit entry, approval request announcement or
  notification fails after the job was saved is answered as launched, as
  the job runs or waits for approval all the same; the application log has
  `Job #42 was launched, but its audit entry, approval request announcement or notification failed: ...`.
  Before, such a launch was answered with an error although its job had
  been saved, and a workflow step could fail next to a job that ran. An
  approval step whose request was written, but whose announcement failed,
  likewise waits for the decision (logged as
  `Workflow job #12: approval request #34 of step #31 was created, but its audit entry or notification failed: ...`).
- A runner's report can no longer overwrite a cancel (see **Runner reports
  after a cancel are ignored**), and a cancel can no longer overwrite a
  finished job or run a workflow route twice.
- A job completion that arrives twice, a second decision or a second resume
  moves a workflow step on only once.
- A workflow cancel writes one `workflow.canceled` audit entry with the
  reason, instead of `workflow.failed` and `workflow.canceled`.
- The `approval.requested` audit entry names the user who launched the job.
  It named whoever's request opened the approval, such as the approver
  whose vote started the next step of a workflow, or nobody for schedules
  and triggers.
- The live status of a job page shows every status in words and colours,
  as the page does when it loads: `Awaiting Approval`, `Timed Out` and
  `Rejected` turned into `Pending_approval`, `Timed_out` and a grey
  `Rejected` as soon as the page updated itself.
- `approval_rule.created_by` can no longer be changed by a form, and
  `approval_rule.job_template_id`, which never gated anything, no longer by
  a form or the API.
- A database error while a workflow run is canceled or resumed no longer
  answers a bare server error. The web UI shows
  `Canceling workflow run #12 failed with a database error; see the application log. ...`
  or `Resuming workflow run #12 failed with a database error; see the application log. ...`
  as an error, with what to do, `POST /api/v1/workflow-jobs/{id}/cancel`
  and `/resume` answer `500` with that message, and the application log has
  `Workflow run #12: the cancel failed with a database error: ...` (see
  [Canceling or resuming a workflow run fails with a database error](troubleshooting.md#canceling-or-resuming-a-workflow-run-fails-with-a-database-error)).
- Workflow notifications are sent after the run's state is written, and one
  that fails, for example on a database error while its notification
  templates are read, no longer stops the run: the application log has
  `Workflow job #12: the workflow.step_failed notification failed: ...`.
- A database error is no longer written with its message, which carries the
  SQL statement with its values, but as its class, SQLSTATE and driver
  error code, also by older code: in the `error` of a `notification.failed`
  audit entry and in the log line of a failing notification channel
  (`NotificationDispatcher: failed to send notification #3 (webhook) for job.failed: ...`),
  in the log line of a failing audit target
  (`AuditService: target ... failed: ...`), and in the log lines and the
  `schedule.failed_to_launch` notification of a schedule that could not be
  checked or launched
  (`Schedule #12 (Nightly deploy) skipped: yii\db\Exception (SQLSTATE 40001, driver error 1213)`).
  The same holds for the sync error of a project, which its page and the
  `project.sync_failed` notification show, and the log lines of project
  syncs, vault checks and vault scans, for the output of
  `php yii health/check` (except for Redis, whose error keeps its message),
  for the message of a job or workflow launch that failed on such an error,
  such as `Launch failed: PDOException (SQLSTATE HY000, driver error 2006)`
  on the page of a workflow, and for the answer to **Send Test** of a
  notification template.
  Other errors, such as the status a webhook endpoint answered, keep their
  message (see [A notification was not sent](troubleshooting.md#a-notification-was-not-sent)).
- A database error that escaped Yii's wrapping (`PDOException`), such as a
  failed `BEGIN` or `COMMIT`, now shows its SQLSTATE and driver error code
  wherever a database error is named, for example
  `PDOException (SQLSTATE HY000, driver error 2006)`; it read
  `PDOException (SQLSTATE -, driver error -)` before. One that PDO raised
  itself, such as a transaction that is already active, has no codes and
  still reads so.
- Errors that nothing in Ansilume catches are written to the application
  log by the framework: by its error handler in the web UI, the API and the
  console commands, and by the queue-worker for a queue job that failed,
  such as a project sync. A database error among them, also as the previous
  error of another, was written with its message, and so with the SQL
  statement and its values. Now an error whose chain holds a database error
  is written innermost first, then each error that wraps it after `Next`,
  each with its file, line and stack trace, without the arguments of the
  calls: the database error by
  its class, SQLSTATE and driver error code, such as
  `yii\db\Exception (SQLSTATE 40001, driver error 1213)`, and for a
  connection that failed or was lost also by the driver's text, such as
  `yii\db\Exception (SQLSTATE HY000, driver error 2002): Connection refused`;
  an error that wraps it by its class and
  `(message withheld: it wraps a database error)`; any other error with its
  message. Errors of Redis, which the framework reports as database errors
  too, are written by their class, with no codes, such as
  `yii\redis\SocketException (SQLSTATE -, driver error -)`: the command and
  its values are left out. Without debug mode (`YII_DEBUG`), the console
  commands print such an error as `Error: ` and one line, for example
  `Error: RuntimeException (message withheld: it wraps a database error); caused by yii\db\Exception (SQLSTATE 40001, driver error 1213)`,
  which ends up in `docker compose logs` of the container that ran the
  command, such as the schedule-runner; with debug mode they print the
  whole error as before, its message with the statement and its values
  included, while the application log leaves the message out in both
  modes. Errors without
  a database error are written as before, and without debug mode the
  error page and the API's answer show no message of
  such an error, as before. Log files written before the update can still
  hold such statements; to remove them, truncate the files as
  [the notes of 2.8.1](#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets)
  describe under **Purge old logs** (see
  [A page or an API call fails with a server error](troubleshooting.md#a-page-or-an-api-call-fails-with-a-server-error)).
- An error that the framework writes to the application log itself, such
  as a rollback that failed while another error was handled, is written
  the same way when its chain holds a database error: by the error's
  class, SQLSTATE and driver error code, never with a message that can
  hold a statement or a Redis command with its values. Such an entry had
  the error's message, for a failed rollback the `ROLLBACK` statement or
  the driver's text. Every other entry is written as before.
- **Send Test** on the page of a notification template reports a channel
  that did not take the test message: the page shows
  `Test notification failed: ` and the channel's error as an error, and
  `POST /api/v1/notification-templates/{id}/test` answers `502` with
  `Test failed: ` and the error. Both said the test was sent whatever the
  channel answered (`Test notification sent successfully.`,
  `{"data": {"sent": true}}`), as the error went only to the audit entry and
  the log.
- Deleting a runner group in the web UI is refused while runners belong to
  it or job templates use it, as `DELETE /api/v1/runner-groups/{id}`
  refused it already:
  `Cannot delete "eu-west": 2 runner(s) still belong to this group.` or
  `Cannot delete "eu-west": 3 job template(s) reference this group.`,
  shown as an error, and the group stays. The web UI deleted such a group
  with its runners, and took it off every job template that used it, those
  with an approval rule included, whose jobs then waited for good, as no
  runner claims the jobs of a template without a runner group. Move the
  runners or delete them, and give the templates another runner group,
  first (see
  [Deleting a runner group is refused](troubleshooting.md#deleting-a-runner-group-is-refused)).
- A job template created in a project, or moved into it, while the project
  was being deleted was deleted together with the project. Now the delete
  is refused as when the template existed before, with the templates
  counted again:
  `Cannot delete "web": 1 job template(s) still reference this project. Remove or reassign them first.`
  (an error in the web UI, `422` from `DELETE /api/v1/projects/{id}`), and
  the project and its templates stay (see
  [Deleting a project is refused](troubleshooting.md#deleting-a-project-is-refused)).
- A launch with a `limit` longer than 255 characters or a `verbosity`
  below 0 or above 5 is refused before anything is written, with the job
  record's message, such as `Verbosity must be no greater than 5.` or
  `Limit should contain at most 255 characters.`: the launch form shows it
  after `Launch failed: `, and `POST /api/v1/jobs` and job triggers answer
  `422` with it, also for a list or an object as either (see
  [Launch refused: the limit or the verbosity](troubleshooting.md#launch-refused-the-limit-or-the-verbosity)).
  Such a launch failed with a server error: `POST /api/v1/jobs` and job
  triggers answered `500`, and the launch form showed the record's errors
  as JSON; a list or an object as the verbosity was taken for a number.
- The message of a job launch that failed names the failure once: the
  launch form shows, for example,
  `Launch failed: the job could not be saved.`, a re-launch
  `Re-launch failed: the job could not be saved.`, and the application log
  has `Job launch failed: the job could not be saved.` for
  `POST /api/v1/jobs` and
  `Trigger for template #7 failed: the job could not be saved.` for a job
  trigger. They said `Launch failed: Job launch failed: ...`,
  `Re-launch failed: Job launch failed: ...`,
  `Job launch failed: Job launch failed: ...` and
  `Trigger for template #7 failed: Job launch failed: ...`. A due schedule
  whose launch failed so logs
  `Schedule #12 (Nightly deploy) failed to launch: the job could not be saved.`,
  and its `schedule.failed_to_launch` notification carries
  `the job could not be saved.` as the error; both said
  `Job launch failed: the job could not be saved.` there.
- A raw token shows once now, in its card. The token of a runner added on
  its runner group's page, or regenerated with **Regen Token**, and a new
  token on **API Tokens** appeared a second time on that page, in unstyled
  boxes at its top (for a runner: its ID, its name and the token). The top
  of a page shows only alerts now. On installations that run with
  `YII_ENV=dev`, the debug toolbar's data in `runtime/debug/` kept these
  tokens and the TOTP secret of a two-factor setup in clear; it stores
  `****` for them now. Drop the data it kept as **Purge old logs** in
  [the notes of 2.8.1](#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets)
  describes; where others could read it, regenerate the runner tokens,
  replace the API tokens and set up two-factor authentication again.

**API contract change:**

- `approval-rule.create` and `approval-rule.update` are admin permissions:
  `POST` and `PUT /api/v1/approval-rules` answer `403` `Forbidden.` for
  operators. `GET /api/v1/approval-rules/{id}` has `used_by`;
  `DELETE` answers `409` with `error.used_by` for a rule in use. `POST` and
  `PUT` answer `422` for a `job_template_id` other than null
  (`job_template_id is not used by approval rules: set approval_rule_id on the job template (admins only).`)
  and for invalid fields, for example `Approver Config cannot be blank.`,
  `Approver Config must be valid JSON.`,
  `Approver Config must be a JSON object.` for a list or a single value,
  or `Name must be a string.`, and return the
  rule as stored. `approver_config` is always a JSON object: `{}` for a rule
  without approvers, which the API returned as the list `[]`, and for a
  rule whose approver list an older version stored as a list or a single
  value. A `PUT` of such a rule answers `422`
  `Approver Config must be a JSON object.` until it sends an
  `approver_config` object, also when it leaves the field out (see the
  eleventh query under **Check before the update**). The spec documents
  approval rules with named schemas now.
- Job templates return `approval_rule_id` and `approval_rule_name`.
  `POST` and `PUT /api/v1/job-templates` answer `403` for an
  `approval_rule_id` the caller may not set (see **Approval rules are
  managed by admins**). `PUT /api/v1/job-templates/{id}` of a job template
  with an approval rule answers `403` for a change of any field but `name`
  and `description` without `approval-rule.update` (see **Only admins
  change what a gated job template runs**).
  `DELETE /api/v1/job-templates/{id}` answers
  `{"data": {"deleted": true, "canceled_jobs": 2, "disabled_schedules": 1}}`;
  a job whose cancel failed is not counted, and the maintenance run cancels
  it.
- Approval requests return `launched_by`, the status `canceled`, and an
  `approval_rule_id` that is null once its rule was deleted;
  `approval_count` and `rejection_count` leave out the launcher's votes.
  `POST /api/v1/approvals/{id}/approve` and `/reject` answer `409` with
  `error.status` for a request that is resolved, expired or whose job no
  longer waits, `403` for the launcher, and `404` for a request deleted
  during the vote (see **Votes are checked under a lock**).
- `GET /api/v1/analytics/approval-summary` returns `canceled`, also as a CSV
  column after `timed_out`; `avg_decision_seconds` leaves canceled requests
  out, and counts a timed-out request until its `expires_at`.
- `POST /api/v1/jobs` and job triggers answer `422` for the new launch
  refusals. `POST /api/v1/jobs/{id}/cancel` answers `409` with
  `error.status` when the job finished while it was being canceled.
- `POST /api/v1/jobs` answers `422` for a `limit` or a `verbosity` that
  the job record does not take, with its messages in `error.message` and
  each such field with its message in `error.fields`, for example
  `{"error": {"message": "Verbosity must be no greater than 5.", "fields": {"verbosity": "Verbosity must be no greater than 5."}}}`;
  job triggers answer `422` with the message. Both answered `500`, or
  took a list or an object as the verbosity for a number (see **Fixes that
  change behaviour**).
- `POST /api/v1/schedules/{id}/toggle` answers `422` for a schedule whose
  job template was deleted. Enabling a schedule with the toggle, or a
  `PUT /api/v1/schedules/{id}` that changes `job_template_id`,
  `cron_expression`, `timezone` or `extra_vars`, or sends `enabled: true`
  for a disabled schedule, makes the caller its `created_by`, the user its
  jobs run as (see **Schedules run as the user who last changed what or
  when they run**).
- `POST` and `PUT /api/v1/workflow-templates` answer `403` for approval
  steps and gated job steps without `approval-rule.update` (see **Gated job
  steps are for admins too**), and `PUT` answers `409` with
  `error.workflow_job_ids` while a run is active. `DELETE` answers
  `{"data": {"deleted": true, "canceled_runs": 1, "failed_runs": 0}}`.
  `POST /api/v1/workflow-templates/{id}/launch` takes `extra_vars` and
  answers `422` for a refused launch, as `/trigger/fire-workflow` does now.
  A database error is no refusal: the launch answers it with `500` and the
  error's class, SQLSTATE and driver error code, such as
  `yii\db\Exception (SQLSTATE 40001, driver error 1213)`, and the
  application log has
  `Launch of workflow template #5 failed: yii\db\Exception (SQLSTATE 40001, driver error 1213)`.
  It answered `422` with the error's message for one that escaped Yii's
  wrapping, and a bare server error for any other. No run was created,
  unless the error struck while the run's first step started: then the run
  exists and has ended as failed. Launch again.
- `GET /api/v1/workflow-jobs/{id}` returns `extra_vars`, and
  `steps[].step_name` and `steps[].step_type`; `steps[].workflow_step_id`
  can be null. `POST /api/v1/workflow-jobs/{id}/cancel` and `/resume`
  answer `500` with a message that says what to do for a database error.
- A superadmin without any role is now treated as holding every permission
  by every endpoint, as the spec said; some endpoints answered `403`.
- `POST /api/v1/notification-templates/{id}/test` answers `502` with
  `Test failed: ` and the channel's error when the channel did not take the
  test message, and `500` with `Test failed: ` and the error when something
  else failed; it answered `{"data": {"sent": true}}` for a failed delivery
  before.
- Audit: the new action `approval.canceled`, whose `reason` can also be
  `workflow_step_ended`; the reason `approval_request_missing` in
  `job.canceled`; the launcher as the user of `approval.requested`;
  `applied_action` and `reason` in `approval.timed_out`; `source`,
  `canceled_jobs` and
  `disabled_schedules` in `job-template.deleted`; `approval_rule` in the job
  template entries; `reason` and `job_template_id` in `schedule.toggled`
  when a delete disabled the schedule; `canceled_runs`, `failed_runs` and
  `source` in `workflow-template.deleted`; `approval_rule_id` in
  `workflow-template.step.added`, `.removed` and `.moved`, and
  `approval_rule_ids` in `workflow-template.created` and `.updated` from
  the API when steps are given; `reason` in `workflow.canceled`;
  `workflow_job_id` and `carried_secret_keys` in `job.launched` of workflow
  job steps; `run_as` with `from` and `to` in `schedule.updated` and
  `schedule.toggled` when the user a schedule runs as changes; the `error`
  of `notification.failed` without the message of a database error.
- For code that calls Ansilume's services: `JobCompletionService::cancel()`
  and `WorkflowExecutionService::completeWorkflow()` return whether they
  changed anything, and the third parameter of
  `WorkflowExecutionService::executeStep()` is the step's mapping of the
  previous step's output.

**Database and deployment.** Four migrations run when the app container
starts. Each checks what is there first and does only what is missing, so a
run that failed half way finishes at the next start.

- `m000082_000000_restrict_approval_rule_management` takes
  `approval-rule.create` and `approval-rule.update` from the operator role,
  and grants them to the admin role directly where it held them only
  through operator. It prints what it changed, for example
  `> Removed approval-rule.create from the operator role.` and
  `> Operators no longer create or change approval rules. To let other users manage them, grant approval-rule.create and approval-rule.update to a custom role.`
- `m000083_000000_protect_approval_rule_references` lets the database refuse
  to delete a rule that job templates or workflow steps use, and keeps the
  approval requests of a deleted rule with `approval_rule_id` null instead of
  deleting them with their votes. Rule IDs of workflow steps that point to
  no rule are cleared first, one line each, for example
  `> Cleared approval rule #9, which does not exist, from workflow step #31.`;
  a workflow with such an approval step is refused at launch with
  `Step "Release approval": its approval rule no longer exists. An admin can choose another rule.`,
  and a run that reaches it fails with
  `The approval rule of this step no longer exists.` An admin removes the
  step and adds it again with a rule.
- `m000084_000000_keep_workflow_run_history` keeps the step records of runs
  when steps are removed (`workflow_job_step.workflow_step_id` becomes
  nullable), copies the name and type of each step into its records
  (`step_name`, `step_type`), and adds `extra_vars` and `secret_extra_vars`
  to `workflow_job`, for the launch extra vars of a run.
- `m000085_000000_close_orphaned_approval_requests` closes the pending
  approval requests whose job no longer waits for approval, and disables
  the enabled schedules of deleted job templates. It prints
  `> Closed N pending approval request(s) whose job no longer waits for approval.`
  and `> Disabled N schedule(s) of deleted job templates.`

Code of this release that runs before the migrations works with the old
tables: the maintenance run and `php yii approval/check-timeouts` apply no
timeouts and sweep nothing while migrations are pending, and a workflow
launch with extra vars fails (the web UI and the API show
`The database update has not finished; run the migrations.`). The queue-worker
runs as one long process and keeps the table schema it read when it
started: once the app container has run the migrations, restart it and the
schedule-runner with `docker compose restart queue-worker schedule-runner`.
No new setting is needed in `.env`, and runners need no update.

- Prebuilt images and quickstart: `docker compose pull && docker compose
  up -d`, or quickstart `--update`, then the restart above.
- Git checkout: right after `git pull`, run
  `docker compose up -d --build --force-recreate`, as for 2.9.0, then the
  restart above.
- Ansible deploy role: run the playbook again, then the restart above.

To roll back to 2.9.0, restore the database backup taken before the update
(see [Rollback](#rollback)). Running these migrations down instead gives the
operator role its rule permissions back, but deletes the step records of
removed workflow steps and the approval requests of deleted rules, which the
old schema cannot hold, and prints how many.

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
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_vars, '$."<field name>"')) AS BINARY) <> CAST('$encrypted$' AS BINARY);

-- The same for schedules
SELECT id FROM schedule
WHERE job_template_id = <template id>
  AND JSON_VALID(extra_vars)
  AND JSON_CONTAINS_PATH(extra_vars, 'one', '$."<field name>"')
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_vars, '$."<field name>"')) AS BINARY) <> CAST('$encrypted$' AS BINARY);

-- Job templates whose own extra vars hold <field name>
SELECT id FROM job_template
WHERE JSON_VALID(extra_vars)
  AND JSON_CONTAINS_PATH(extra_vars, 'one', '$."<field name>"');
```

Keep the `CAST(JSON_UNQUOTE(...) AS BINARY)` form: it compares the answer
with the placeholder byte for byte, as Ansilume does, so `$ENCRYPTED$` or
the placeholder with a trailing space count as answers, and on MariaDB,
comparing `JSON_EXTRACT(...)` with `JSON_QUOTE('$encrypted$')` also matches
rows that hold the placeholder. Earlier versions of these notes used the
`BINARY` operator, which lists the same rows but which MySQL 8 reports as
deprecated.

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
    WHERE g.id = (SELECT MAX(h.id) FROM audit_log h
      WHERE h.action IN ('job-template.trigger-token.generated',
                         'job-template.trigger-token.revoked')
        AND h.object_type = 'job_template' AND h.object_id = t.id)) AS token_generated_by,
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
    WHERE g.id = (SELECT MAX(h.id) FROM audit_log h
      WHERE h.action IN ('workflow-template.trigger-token.generated',
                         'workflow-template.trigger-token.revoked')
        AND h.object_type = 'workflow_template' AND h.object_id = t.id)) AS token_generated_by,
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
  entry: the token was not generated on the template page. The query finds
  the newest entry with `MAX(h.id)` because MariaDB can answer the earlier
  form of these notes, `ORDER BY g.id DESC LIMIT 1`, with an empty value
  although the entry exists, which made such a token look like one to
  revoke.
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

To roll back from the update to approvals and workflows (migrations
`m000082` to `m000085`), restore the backup taken before it, see
**Database and deployment** under
[After updating from 2.9.0 or older](#after-updating-from-290-or-older-approvals-and-workflows).

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

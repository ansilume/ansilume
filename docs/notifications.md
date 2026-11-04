# Notifications

A notification template sends the events it listens to through its
channel: email, Slack, Microsoft Teams, a webhook, Telegram, PagerDuty,
Mattermost, or a channel of your own. The process in which an event
happens, such as a runner's report, a vote, a web request or the
maintenance run, sends nothing itself: it writes a delivery for each
template that gets the event, and the notification-worker sends the
delivery from a queue of its own, with retries (see [Delivery](#delivery)).
So a notification that fails, or an endpoint that is slow, never holds up a
workflow, the end of a job or a vote.
[Troubleshooting](troubleshooting.md#a-notification-was-not-sent) says what
to do when one was not sent.

## Notification templates

**Notifications** lists the templates. A template has:

- a name and a description;
- its channel, with the channel's config (see [Channels](#channels));
- the events it listens to (see [Events](#events));
- its level and its workflows (see
  [Which events a template gets](#which-events-a-template-gets));
- a subject and a body, in which `{{ job.id }}` and the other
  [variables](#variables) of the event are replaced with their values, and
  a variable the event does not have with nothing.

`notification-template.view` shows the templates, `notification-template.create`
creates one, `notification-template.update` changes one and sends its tests,
and `notification-template.delete` deletes one. Of the built-in roles,
viewers view, operators also create, change and test, and admins also
delete.

### The template's page

Besides its config, with every channel secret shown as `$encrypted$`, a
template's page shows:

- **Delivers as**: the user whose access decides what the template
  delivers (see [Delivers as](#delivers-as)), and a warning while that user
  is inactive: `Delivers nothing: alice, the user it delivers as, is inactive. Saving the template makes whoever saves it the user it delivers as.`
- **Workflows**: `All workflows`, or the workflow templates of its
  selection that you may see, and `+ 2 workflow template(s) you cannot see`
  for the others.
- **Level**: `Everything` or `Only failures, waits and the end`.
- For Mattermost, the last check of its bot token and its channel, such as
  `Checked 2026-11-04 10:15: the bot @ansilume may post to channel h8kc6rz1ytgq3mbxd9wfs5ea4n.`
  (see [The checks](#the-checks)).
- **Deliveries**: the template's deliveries, newest first, 25 per page:
  their event, kind (`Message`, `Status post` or `Reply`), status, attempts,
  what each is about (its run, job, approval request, project or job
  template, linked), the last error, and when it was made and when it
  finished, or when its next attempt comes. You see only the deliveries of
  events you may see in Ansilume, every object they name included;
  `+ 3 delivery(ies) you cannot see` counts the others.

### Send Test

**Send Test** on the template's page, or
`POST /api/v1/notification-templates/{id}/test`, sends one message through
the template's channel at once, with sample values of the variables, for
the template's first event that its channel sends something for. The page
then says `Test notification sent successfully.`, or
`Test notification failed: ` and the channel's error; the API answers
`{"sent": true}`, or `502` with `Test failed: ` and the error. A test of a
Mattermost template checks its bot token and its channel first. A test of a
webhook is signed as a delivery is. Every test writes a
`notification.dispatched` or `notification.failed` audit entry, with the
user who sent it.

## Channels

| Channel | Config | Channel secrets |
|---|---|---|
| Email (`email`) | `emails`: a list of addresses; the [mail settings](installation.md#email--notifications) of the installation send them | none |
| Slack (`slack`) | `webhook_url`: an incoming webhook of Slack | `webhook_url` |
| Microsoft Teams (`teams`) | `webhook_url`: an incoming webhook of Teams | `webhook_url` |
| Webhook (`webhook`) | `url`, `headers`, `signing_secret` and `payload`: see [The webhook channel](#the-webhook-channel) | `url`, `signing_secret` and the values of `headers` |
| Telegram (`telegram`) | `bot_token` of a Telegram bot and `chat_id` of the chat it posts to | `bot_token` |
| PagerDuty (`pagerduty`) | `routing_key` of an Events API v2 integration | `routing_key` |
| Mattermost (`mattermost`) | `server_url`, `bot_token`, `channel_id` or `team` and `channel`, and `mention`: see [The Mattermost channel](#the-mattermost-channel) | `bot_token` |

The template's page, its edit form and the API show every channel secret
as `$encrypted$`, and a save that sends `$encrypted$` back keeps the stored
value. A secret that goes along with an address keeps it only while the
address keeps its stored value: the webhook's `headers` and `signing_secret`
go along with `url`, Mattermost's `bot_token` with `server_url`. Change the
address, and enter the secret again
(`bot_token: server_url changed; enter the real value.`): nobody can send a
stored secret to an address of their own.

PagerDuty raises an incident of the event's severity, and resolves it with
`job.succeeded`, `workflow.succeeded`, `project.sync_succeeded` and
`runner.recovered`. It raises none for `workflow.step_started` and
`workflow.step_succeeded`, so a PagerDuty template gets no delivery of them.

### Outbound HTTP

Every channel but email sends through one HTTP client, and so do the
outbound webhooks (**Webhooks**):

- It sends only to absolute `http` and `https` URLs, and a template is saved
  only with such URLs (`webhook_url must be an http or https URL.`).
  Mattermost's `server_url` must be an https URL
  (`server_url must be an https URL.`): the bot token is a full session of
  its bot.
- It follows no redirect: a `3xx` answer is an error.
- It waits 5 seconds for the connection, the TLS handshake included, and 15
  seconds for the whole request, and reads at most 65536 bytes of the
  answer.
- It always verifies TLS, and no setting turns that off.
  `OUTBOUND_CA_BUNDLE` names a PEM file of CAs that it trusts on top of the
  system's, for an endpoint whose certificate an internal CA issued.
- Its errors say only the HTTP status, or why no answer came, such as
  `Could not connect (curl error 7).`, `The request timed out after 15 seconds.`,
  `The TLS connection failed (curl error 60).` or
  `The URL must use http or https.`: never the URL, a header, a token or
  what the endpoint answered.

`OUTBOUND_CA_BUNDLE` names the file inside the containers that send: the
app, the queue-worker, the notification-worker and the schedule-runner.
Each of them reads `.env` when it starts.

- With the prebuilt images, mount the file into those four services with a
  compose file of your own beside the one you run, and set
  `OUTBOUND_CA_BUNDLE=/etc/ansilume/internal-ca.pem` in `.env`.
  `docker compose` reads a `docker-compose.override.yml` by itself; where
  you name the compose file with `-f`, as an installation the quickstart
  made from source does, name the override with a second `-f`:

  ```yaml
  services:
    app:
      volumes: ["./internal-ca.pem:/etc/ansilume/internal-ca.pem:ro"]
    queue-worker:
      volumes: ["./internal-ca.pem:/etc/ansilume/internal-ca.pem:ro"]
    notification-worker:
      volumes: ["./internal-ca.pem:/etc/ansilume/internal-ca.pem:ro"]
    schedule-runner:
      volumes: ["./internal-ca.pem:/etc/ansilume/internal-ca.pem:ro"]
  ```

  Then recreate the containers with `docker compose up -d`, which also
  recreates them for a changed `.env`, as each names it as its `env_file`.
  In an installation the quickstart made from source, run
  `docker compose -f docker-compose.prebuilt.yml -f docker-compose.override.yml up -d`:
  there a plain `docker compose up -d` starts the development stack of
  `docker-compose.yml`.
- A source installation started from `docker-compose.yml` sees its checkout
  at `/var/www`: put the file into the checkout and name it at
  `/var/www/<its path>`. Its services name no `env_file`, so
  `docker compose up -d` leaves them running with the `.env` they started
  with: restart them
  (`docker compose restart app queue-worker notification-worker schedule-runner`).
  Until then **Send Test**, which the app serves with a fresh `.env` for
  each request, works while the deliveries of the notification-worker keep
  failing.
- With the deploy role, set `ansilume_outbound_ca_bundle`. With its prebuilt
  images (`ansilume_use_prebuilt_images`), the containers do not see the
  install directory: put the override above into it, where the role uses
  it. The role recreates the containers.

The system's CAs stay trusted.

## Events

| Event | Severity | When |
|---|---|---|
| `job.launched` | info | A job was queued: launched in the web UI or the API, by a schedule, a trigger or a workflow step. A job that waits for approval sends `approval.requested` instead. |
| `job.succeeded` | info | A job succeeded. |
| `job.failed` | critical | A job failed or timed out. |
| `job.canceled` | error | A job was canceled. |
| `workflow.launched` | info | A workflow run was launched. |
| `workflow.step_started` | info | A step of a run started: its job was launched, its approval requested or its pause reached. |
| `workflow.step_succeeded` | info | A step succeeded: its job succeeded, its approval was granted or its pause was resumed. |
| `workflow.step_failed` | error | A step failed, also one that could not start: an unknown step type, a refused or failed launch, a missing approval rule. The steps that a cancel ends send none. |
| `workflow.waiting` | warning | A run waits for a person: for the approval of an approval step, or of a job step whose job waits for approval, or for someone to resume its pause step. |
| `workflow.succeeded` | info | A run succeeded. |
| `workflow.failed` | critical | A run failed. |
| `workflow.canceled` | error | A run was canceled; sent once the cancel has ended its running steps. |
| `approval.requested` | warning | A job waits for approval. |
| `approval.approved` | info | An approval request was approved. |
| `approval.rejected` | error | An approval request was rejected. A request that times out sends no approval event. |
| `schedule.failed_to_launch` | critical | A schedule could not launch its job template. |
| `runner.offline` | critical | A runner stopped reporting. |
| `runner.recovered` | info | A runner that was offline reports again. |
| `project.sync_failed` | critical | A project sync failed. |
| `project.sync_succeeded` | info | A project sync succeeded. |
| `webhook.invalid_token` | error | A trigger was called with a token that no template has: at most once per template every 5 minutes; every further call is in the audit log as `notification.throttled`. |

The severity is the variable `{{ severity }}`; PagerDuty raises its
incidents with it, and Mattermost colours its posts by it.

Each event fires once, when the change it names happens: a step that a loop
runs again fires its events again, once per run of the step, and a refused
change, such as a second report of a job that ended, fires nothing.

The runner events come from `php yii health/check-runners`, which no
compose file and no deploy role runs yet. To get them, run it every minute
or two, for example from the cron of the host, in the install directory:

```bash
* * * * * cd /opt/ansilume && docker compose exec -T -u www-data app php yii health/check-runners > /dev/null
```

## Variables

Every value is text. The variables of every event:

| Variable | What it holds |
|---|---|
| `event` | the event, such as `job.failed` |
| `severity` | its severity: `info`, `warning`, `error` or `critical` |
| `event_id` | 32 hexadecimal digits that name the event: every template the event reaches gets the same, and no other event has it |
| `occurred_at` | when it happened, as a Unix time |
| `timestamp` | when it happened, as the server's local time, such as `2026-11-04 10:15:00 UTC` |
| `app.url` | the address of Ansilume, `APP_URL`, from which every link of a notification is made (see [Links in notifications lead to localhost](troubleshooting.md#links-in-notifications-lead-to-localhost)) |

And those of its group:

- **Job events:** `job.id`, `job.status`, `job.exit_code`, `job.duration`
  (in seconds, such as `83s`), `job.url` (the job's page), `job.template_id`,
  `template.id` and `template.name` (its job template), `project.id` and
  `project.name`, `launched_by` (the username of who launched it).
- **Workflow events:** the run: `workflow.id`, `workflow.status` (as stored:
  a run that waits is `running`), `workflow.template_id`,
  `workflow.template_name`, `workflow.url` (the run's page),
  `workflow.steps_done` (the steps of the workflow that ended in the run,
  succeeded, failed or skipped, each counted once however often a loop ran
  it), `workflow.steps_total` (the steps the workflow has now) and
  `workflow.reason`: what the run's page says, the title of its banner
  while the step waits, such as `Job #42 of step "Deploy" waits for approval.`,
  else the step's error. Its step: `step.id` and `step.execution_id` (this
  run of the step: a step that a loop runs again gets a new one),
  `step.job_id`, `step.workflow_step_id`, `step.position` (its
  place 1..n in the workflow now), `step.name`, `step.type` (`job`,
  `approval` or `pause`), `step.waiting_for` (`approval`, `resume` or
  `runner`, empty while it waits for nothing); and `approval.url` while its
  job waits for approval. An event of the whole run (launched, succeeded,
  failed, canceled) names the run's newest step.
- **Approval events:** `approval.id`, `approval.status`, `approval.rule_id`,
  `approval.rule_name`, `approval.required_by` (`job_template`,
  `inventory` or `approval_step`), `approval.url` (the request's page), and
  its job: `job.id`, `job.status`, `job.template_id`, `job.inventory_id`,
  `job.inventory_name` (the inventory it was launched with).
- The job and approval events of a workflow step carry the run's variables
  too (`workflow.*`, `step.*`), but only to a template whose user may see
  the run's workflow.
- **`schedule.failed_to_launch`:** `schedule.id`, `schedule.name`,
  `schedule.cron`, `schedule.error`.
- **Runner events:** `runner.id`, `runner.name`, `runner.last_seen_at` (a
  Unix time).
- **Project events:** `project.id`, `project.name`, `project.status`,
  `project.error` (the sync error, without the credentials of a repository
  URL).
- **`webhook.invalid_token`:** `trigger.token_prefix` (the first six
  characters of the token), `trigger.kind` (`workflow` for the trigger of a
  workflow template), `trigger.ip`, `trigger.user_agent` and `trigger.note`,
  which says that further calls are in the audit log.

A value is kept to at most 8192 bytes, cut with `…`, so that a delivery
always fits its row. No variable holds extra vars, survey answers,
credentials, tokens or job output.

## Which events a template gets

A template gets a delivery of an event when:

1. it listens to the event;
2. its level lets the event through (see [The level](#the-level));
3. for an event of a workflow run, its workflows take the run (see
   [The workflows](#the-workflows));
4. the user it delivers as may see the event in Ansilume (see
   [Delivers as](#delivers-as));
5. its channel sends something for the event: PagerDuty sends nothing for
   `workflow.step_started` and `workflow.step_succeeded`.

On Mattermost, a template that has a status post of a run gets every later
change of the run as an edit of that post, whatever its events and its
level say (see
[Workflow runs: the status post and its replies](#workflow-runs-the-status-post-and-its-replies)).

### The level

| Level | In the form | The workflow events it delivers |
|---|---|---|
| `all` | Everything | every workflow event it listens to |
| `essential` | Only failures, waits and the end | of the workflow events it listens to, only `workflow.step_failed`, `workflow.waiting`, `workflow.succeeded`, `workflow.failed` and `workflow.canceled` |

The level applies to workflow events only: every other event the template
listens to reaches it at both levels. A new template has level `all`.

### The workflows

| Workflow scope | In the form | The runs that reach the template |
|---|---|---|
| `all` | All workflows | the runs of every workflow |
| `selected` | Only these workflows | the runs of the workflow templates it names (`workflow_template_ids`) |

The workflows decide which runs reach the template: their workflow events,
and the job and approval events of their steps. An event that belongs to no
run, such as a job launched outside a workflow or a project sync, reaches
it as its events say.

A selection whose workflow templates were all deleted reaches no run: it
never turns into all workflows. A deleted workflow template stays in the
selection, so that the runs its delete cancels still reach the template,
and the template's page and the API count it among those you cannot see.
The form and the API offer only the workflow templates their user may see,
and a save changes only those: the others stay selected, and the form says
`+ 2 workflow template(s) you cannot see, which stay selected`.

### Delivers as

A template delivers only what the user it delivers as may see in Ansilume.
Its page shows that user as **Delivers as**, the API as `delivers_as`. It
is the user who last changed what the template delivers or where to:

- its channel, or a config key that says where it delivers: `emails`;
  `webhook_url`; `url` and `headers`; `bot_token` and `chat_id`;
  `routing_key`; Mattermost's `server_url`, `bot_token`, `channel_id`,
  `team` and `channel`; every key of a channel of your own without
  `destinationKeys`;
- its events, its level, its workflow scope or its workflows.

A new template delivers as its creator, and any save of a template whose
user is inactive makes whoever saves it its user. A new name, description,
subject or body, another config key (the webhook's `signing_secret` and
`payload`, Mattermost's `mention`) and a save that changes nothing keep the
user it delivers as. So does the same destination in another shape, as the
edit form posts it back: a missing key, `null`, `""` and an empty list or
object are the same, a number is the same as its text (`-100` and
`"-100"`), and the keys of an object may come in any order. A webhook
template that the edit form of 2.14 saved, with `"headers": {}`, keeps its
user when it is renamed. No form and no request body sets it; the
`notification-template.updated` audit entry of a change records it as
`delivers_as` `{from, to}`. Without the rule, whoever edits someone else's
template, say an operator an admin's, would choose where it delivers while
it delivers what its creator may see: the events of every team.

The user must be active, hold the permission of the event's group, and see
the event's object:

| Events | Permission | The user must also see |
|---|---|---|
| Job events | `job.view` | the job |
| Workflow events | `workflow.view` | the run's workflow template |
| Approval events | `approval.view` | the approval request |
| `schedule.failed_to_launch` | `job.launch` | the project of the schedule's job template |
| Runner events | `runner-group.view` | nothing more: the runners serve the whole installation |
| Project events | `project.view` | the project |
| `webhook.invalid_token` | none | nothing more: any active user gets it |

It is checked when the event happens and again when the delivery is sent:
a user who lost access meanwhile, or was deactivated, gets nothing more,
and the delivery is skipped with the reason, such as
`Not sent: the user the template delivers as, #12, is inactive.` A role
that you take from a user or give them counts from the next job of the
queue-worker and the notification-worker on: no restart is needed. A job or
an approval event of a workflow step reaches a user who may see the job but
not the run's workflow without the run's variables (`workflow.*`,
`step.*`), and on Mattermost as a post of its own, outside the run's
thread; so it does when the user lost sight of the workflow after the
event (the workflow got a step of another team's project, or the template
another user): the delivery leaves them out of the subject, the body and
the webhook's event payload. Without a team-restricted project every active user sees what their
roles allow, so a template of an operator or an admin gets every event it
listens to.

A template whose user is inactive delivers nothing: its page warns, the API
answers `delivers_as_inactive: true`, and `bin/diagnose` lists it (see
[A notification template delivers nothing: the user it delivers as is inactive](troubleshooting.md#a-notification-template-delivers-nothing-the-user-it-delivers-as-is-inactive)).
Saving it makes you its user. A user whom a template delivers as cannot be
deleted, as the creator of a template cannot: deactivate the account
instead, and save the template.

## Delivery

Where an event happens, Ansilume writes a delivery for each template that
gets it (`notification_delivery`), with the event's variables as they are
then, and puts the delivery's ID on the notification queue, a Redis queue
of its own (`ansilume-notifications`). The notification-worker takes it from
there and sends it.

The deliveries of a workflow run, its workflow events and the job and
approval events of its steps, go out in the order of their events, on
every channel: each waits until the earlier ones of the same template and
run have ended, so that no message overtakes an earlier one, and on
Mattermost the run's status post is there before its replies and its
edits.

### Retries

A delivery that fails for a reason that can pass, no answer, a timeout, a
failed TLS connection, or the answer `408`, `425`, `429` or `5xx`, is tried
again: after 30 seconds, 2, 10 and 30 minutes, then 1, 2 and 4 hours, at
most 8 attempts, the last about 7.7 hours after the first. When the endpoint
asks for more time with `Retry-After`, in seconds or as an HTTP date, the
next attempt waits that long, up to an hour, and never less than its step.
Any other answer outside `2xx`, such as `400`, `401`, `403`, `404` or
`413`, fails the delivery at once, and so do a URL the client does not
take, a Mattermost config that breaks the channel's rules, and a channel
the installation does not have; any other error, such as an email that the
mailer could not send, is tried again. A delivery that failed for good is
not sent again; the next event is.

While a delivery of a run waits for its next attempt, the later deliveries
of the same template and run wait behind it, without an attempt of their
own: they show `pending` until it has ended, at the latest after its 8th
attempt.

### What a delivery ends as

| Status | What it says | Audit entry |
|---|---|---|
| `pending` | it waits for its first attempt, or its next one | none yet |
| `sending` | a worker sends it now | none yet |
| `delivered` | its channel took it | `notification.dispatched`, with the subject |
| `failed` | its last attempt failed, or a failure that does not pass | `notification.failed`, with the error of the channel |
| `skipped` | when it was to be sent, the user the template delivers as could no longer see its event, or the template's channel, changed meanwhile, sends nothing for it | `notification.skipped`, with the reason |
| `superseded` | a later edit of the same Mattermost status post took its place before it was sent | none |

Each delivery writes one audit entry, for its outcome, never one per
attempt. The entry carries no user: it names the template, its channel, the
event, the delivery, its kind and its attempts.

### The sweep

Every minute the maintenance run of the schedule-runner sweeps the
deliveries (`notification-sweep`):

- it queues a pending delivery again that has been due for more than 2
  minutes, as after Redis started empty or a push failed, while the
  notification queue holds no message that waits, that a worker took, or
  whose delay has passed: while it holds one, the notification-worker is
  behind or does not run, and a message queued again would only wait
  behind the others, so however long no worker takes them, a delivery gets
  at most one more message. A message delayed to later, such as a retry's,
  does not hold it back;
- it gives back a delivery that a worker claimed more than 3 minutes ago and
  did not finish, as when the worker stopped while it sent it: the lost
  attempt counts, and the next one comes at once; a delivery that was on its
  last attempt fails with
  `The notification worker stopped while it sent the delivery.`;
- it removes the deliveries that finished more than 30 days ago.

When it changed something, it prints, for example,
`[maintenance] ran: notification-sweep (repushed=1, reset=0, purged=0)`.
The deliveries are kept in the database, not in Redis: none is lost when
Redis is, or when the worker stops.

### The notification-worker

The service `notification-worker` (container `ansilume_notification_worker`)
runs `php yii worker/start --queue=notificationQueue` on the app image, as
the queue-worker does, in `docker-compose.yml`, in
`docker-compose.prebuilt.yml` and in the deploy role, with the same `.env`.
It needs:

- Redis and the database;
- outbound connections to every endpoint of the templates: the Slack, Teams
  and webhook URLs, `api.telegram.org`, `events.pagerduty.com`, your
  Mattermost server; for email, the SMTP server;
- the file of `OUTBOUND_CA_BUNDLE`, when it is set (see
  [Outbound HTTP](#outbound-http)).

With the prebuilt images it needs no volume; in a git checkout it runs the
code of the checkout, as the queue-worker does. It opens a database
connection for each delivery and closes it after, and a delivery takes at
most the queue's 120 seconds: on a stop, the worker finishes the delivery it
sends (`stop_grace_period: 120s`).

`docker compose ps notification-worker` shows whether it runs, and
`docker compose logs notification-worker` only that it started
(`[worker] started. PID=1 Host=5176c8044aab Queue=notificationQueue`). Its
application log has a line for each attempt that failed and for each
delivery that failed for good, such as
`NotificationDeliveryService: delivery #41 of notification template #3 (webhook) for job.failed failed on attempt 1 of 8; the next attempt in 30 seconds: WebhookChannel: HTTP 503.`:
in a git checkout `runtime/logs/app.log`, which every container writes;
with the prebuilt images a log of its own, inside its container:

```bash
docker compose exec notification-worker tail -n 200 /var/www/runtime/logs/app.log
```

## The Mattermost channel

A bot posts to one channel of a Mattermost server, through Mattermost's REST
API v4, over https: Mattermost 10.11 ESR or later.

### Setting it up

1. Allow bot accounts: **System Console → Integrations → Bot Accounts →
   Enable Bot Account Creation**.
2. Create the bot: **Integrations → Bot Accounts → Add Bot Account**, with
   the role **Member**, and copy its access token, which Mattermost shows
   once. A personal access token does not work: it carries the rights of a
   person.
3. Add the bot to the team, for example under **System Console → User
   Management → Teams → (the team) → Add Members**, and to the channel, with
   `/invite @ansilume` in the channel, a private channel too. The bot must
   be a member, and members must be allowed to edit their own posts
   (**Edit Posts**, which Mattermost's permission schemes allow by default):
   it cannot edit its status posts otherwise.
4. In Ansilume, create a notification template with the channel
   **Mattermost**: the server's address as **Server URL**, such as
   `https://chat.example.com`, the bot's token as **Bot Token**, and the
   channel by the names in its address
   (`https://chat.example.com/ops/channels/deployments`: team `ops`,
   channel `deployments`) or by its ID, which the channel's **View Info**
   shows; and optionally a **Mention**.
5. **Send Test**.

A bot's token lasts until it is revoked. Mattermost disables a bot when the
user who owns it is deactivated (**Disable bot accounts when owner is
deactivated**, on by default): create it with an account that stays.

### Its config

| Key | What it is |
|---|---|
| `server_url` | The address of the Mattermost server: an https URL (`server_url must be an https URL.`), without a user, a query or a fragment, at most 255 characters; a path is kept, for a server below one. |
| `bot_token` | The bot's access token: a channel secret, shown as `$encrypted$`, which keeps its stored value only while `server_url` keeps its own (`bot_token: server_url changed; enter the real value.`), so that a stored token never goes to another server. |
| `channel_id` | The channel by its ID, 26 lower-case letters and digits, which survives a rename of the channel. |
| `team`, `channel` | Or the channel by the names in its address, such as `ops` and `deployments`. Give `channel_id`, or `team` and `channel` (`Give channel_id, or team and channel, not both.`). |
| `mention` | Optional: one user or group mention, such as `@ops-oncall`, that the replies which need attention start with. Never `@all`, `@channel` or `@here` (`mention may not be @all, @channel or @here, which notify everyone in the channel.`). |

Saving checks the form of these only, and never asks Mattermost: an outage
of Mattermost never blocks an edit.

### The checks

Send Test, and the first delivery after the server, the token or the
channel changed, ask Mattermost three things:

1. whether the token is a bot's (`GET /api/v4/users/me`): a personal
   access token is refused;
2. whether the channel exists for the bot, looked up by its ID or its
   names, and is not archived;
3. whether the bot is a member of the channel.

A check that passed is kept with the template, never in its config, and
its page shows it, such as
`Checked 2026-11-04 10:15: the bot @ansilume may post to channel h8kc6rz1ytgq3mbxd9wfs5ea4n.`;
the deliveries after it ask nothing. A check that failed is not kept, so
the next delivery asks again: adding the bot to the channel takes effect
without a change of the template. Send Test then posts a test message.

### Workflow runs: the status post and its replies

A template keeps one post per workflow run in its channel, the status
post:

- The run's first event that the template listens to and its level lets
  through, a workflow event or a job or approval event of one of its steps,
  creates it.
- Every later change of the run edits it, whatever the template's events
  and level say, so that it never stays behind the run.
- It shows the run as the run's page does, to the user the template
  delivers as: the run and its workflow, linked to the run's page, in the
  colour of the run's state; what the run does now (what it waits for, with
  the link to the approval request, the step that runs, or its result);
  each step that ran, with its position and state, the last 30 of a run
  that ran more; the steps done of the workflow's steps; and the run's
  duration once it ended.

An edit notifies nobody. The run's events go as short replies into the
status post's thread, each with one link, to the approval request a step
waits for, to the job or to the run:

- at level `all`: `workflow.step_started`, `workflow.step_succeeded`,
  `workflow.step_failed`, `workflow.waiting`, `workflow.succeeded`,
  `workflow.failed` and `workflow.canceled`;
- at level `essential`: `workflow.step_failed`, `workflow.waiting` and the
  run's end;
- the job and approval events of the run's steps that the template listens
  to.

`workflow.launched` only creates the post. A reply notifies only those who
follow the thread, and those it mentions: the replies of
`workflow.waiting`, `workflow.step_failed`, `workflow.failed` and
`workflow.canceled` start with the template's `mention`. A template that
listens to failures only shows nothing of a run until its first failure;
its post then stays current until the run ends.

A status post that was deleted, or that Mattermost's edit time limit
(`ServiceSettings.PostEditTimeLimit`) no longer lets the bot edit, is
posted anew, and the run's thread goes on under the new one; so does a
reply whose status post was deleted. Mattermost refuses an edit it does not
allow as it refuses the edit of a deleted post, so a status post whose edit
it refused twice is looked up first: where the bot may not edit its own
posts, because a permission scheme of the system or the team takes **Edit
Posts** from members, its status deliveries fail with
`Mattermost does not let the bot edit its own status post …`, and nothing
is posted anew, so that the channel does not get a post for every change
of the run. After a change of `server_url` or the
channel, a run gets a new status post where the config points, and the old
one is never edited again.

### Other events

An event outside a run's thread, such as a job outside a workflow, a project
sync, a schedule, a runner, an approval outside a workflow, or an event of a
run whose workflow the user it delivers as may not see, is a post of its
own: one attachment in the colour of its severity, with the template's
subject as its title and its body as its text, linked to the run, the
approval request or the job.

### Names, Markdown and mentions

Mattermost reads the text of a post as Markdown and looks for mentions in
it. A value that Ansilume did not write, a workflow, step, template,
project or user name, an error, every variable in the subject and the body,
is shown as plain text: each character that Markdown reads is escaped with
a backslash, a line break becomes a space, and each `@` is followed by a
zero-width space, so that no value mentions anyone, `@all`, `@channel` and
`@here` included. The template's own text stays Markdown as written. A text
is cut at 8000 characters, with a link to the rest in Ansilume, well below
the 16383 that Mattermost takes.

Where Mattermost shows link previews (**Enable website link previews**) and
can reach Ansilume, the link of a reply may get a preview of the page it
opens, which is mostly Ansilume's login page. To leave them out, add
Ansilume's address to **Disable website link previews from these
domains**.

### Limits

- A delivery makes at most 7 requests to Mattermost: the check after a
  change (3 requests), then an edit, its one retry, the lookup of the post
  and a new status post; or the check, a reply, a new status post and the
  reply again, one of them sent twice after a lost answer. Each takes at
  most 15 seconds, so that a delivery ends within the 120 seconds of the
  notification queue.
- A post carries a pending post ID, which Mattermost keeps for 30 seconds
  after it made the post, so that the same create sent again within them
  gets that post. A create whose answer did not come, or came as a `5xx`
  (a connection that broke, the timeout, a proxy's error after Mattermost
  made the post), is sent once more 2 seconds later, once per delivery and
  while the delivery has a request left. The next attempt of a delivery
  comes 30 seconds later at the earliest, after Mattermost forgot the ID: a
  post whose answer was lost twice, or that was made but not recorded
  because the worker stopped in between, may be posted twice.
- Its errors name the HTTP status, Mattermost's error id and the request ID
  (`X-Request-ID`), which Mattermost's logs know; never the server's
  address, the token or what Mattermost answered (see
  [A Mattermost notification fails](troubleshooting.md#a-mattermost-notification-fails)).

## The webhook channel

A webhook template sends each event as a JSON `POST` to its `url`. Its
config:

| Key | What it is |
|---|---|
| `url` | Where the requests go: an absolute http or https URL. A channel secret: the template's page, its edit form and the API show it as `$encrypted$`. |
| `headers` | Optional: an object of header names and values that every request carries, such as `Authorization`. The values are channel secrets, shown as `$encrypted$`; they keep their stored values only while `url` keeps its own. A value is one line of text, a number or null. A header named as one the channel sets itself (`X-Ansilume-Signature`, `X-Ansilume-Delivery`, `X-Ansilume-Event`) is refused: `headers.X-Ansilume-Event is set by Ansilume; leave it out.`, and so are headers that are no object (`headers must be an object of header names and values.`), a name that is no HTTP header name (`headers.X A is no header name: leave out spaces, quotes, colons and the other separators.`) and a value that is a list, an object or more than one line (`headers.X-A must be one line of text.`). |
| `signing_secret` | Optional: with it, every request is signed (see below). A channel secret, shown as `$encrypted$`; it keeps its stored value only while `url` keeps its own, so enter it again when you change the URL (`signing_secret: url changed; enter the real value.`). Text of at most 256 characters; use a long random value, such as the output of `openssl rand -hex 32`. |
| `payload` | Optional: `template`, the default and what every template made before the setting sends, or `event`, the event payload below. Anything else is refused: `payload must be template or event.` |

Changing `signing_secret` or `payload` does not change the user the
template delivers as: neither says where it delivers or what.

### What each request carries

- `Content-Type: application/json`.
- `X-Ansilume-Event: <event>`, such as `workflow.step_failed`.
- `X-Ansilume-Delivery: <delivery ID>`: the ID of the delivery, the same on
  every retry of it. A test send (Send Test, or `POST
  /api/v1/notification-templates/{id}/test`) has no delivery: it carries
  `test-` and 16 hexadecimal digits, another for every test.
- `X-Ansilume-Signature: t=<unix time>,v1=<signature>` when the template has
  a `signing_secret`: `t` is the time the request was signed, as a Unix
  time, and the signature is the HMAC-SHA256 of `<t>.<body>` (the time, a
  dot and the body exactly as sent) with the signing secret as the key, in
  lower-case hexadecimal. A retry is signed again, at its own time.
- The headers of the config.

Only an answer with a 2xx status delivers a request, and Ansilume follows
no redirect. A request that failed (no answer, a timeout, a 408, 425, 429
or 5xx answer) is retried, so a receiver can get a request twice, for
example one it answered after the timeout: skip a delivery whose
`X-Ansilume-Delivery` you have seen before, or an event whose `event_id`
you have seen before. A delivery ID counts up within one installation and
starts again when its outbox does (going back with `migrate/down`, a
database restored from before), so keep the IDs you have seen only for a
few days, and tell installations apart where one receiver serves several.
`event_id` is random, and the same for every template the event reaches.

### The template payload

With `payload` `template`, the body holds the template's subject and body,
rendered, and the event's variables:

```json
{
  "subject": "Job #42 failed",
  "body": "Template: Deploy",
  "variables": {"event": "job.failed", "severity": "critical", "job.id": "42", "job.url": "https://ansilume.example.com/job/view?id=42"}
}
```

### The event payload

With `payload` `event`, the body is the event, shaped like the resources
of the REST API:

```json
{
  "event": "workflow.waiting",
  "event_id": "8f14e45fceea167a5a36dedd4bea2543",
  "occurred_at": 1762250000,
  "severity": "warning",
  "workflow": {
    "id": 12,
    "workflow_template_id": 8,
    "template_name": "Release",
    "status": "running",
    "steps_done": 1,
    "steps_total": 3,
    "reason": "Job #42 of step \"Deploy\" waits for approval.",
    "url": "https://ansilume.example.com/workflow-job/view?id=12"
  },
  "step": {
    "id": 31,
    "workflow_step_id": 17,
    "position": 2,
    "step_name": "Deploy",
    "step_type": "job",
    "job_id": 42,
    "approval_request_id": 9,
    "waiting_for": "approval"
  },
  "approval": {
    "id": 9,
    "status": null,
    "approval_rule_id": null,
    "approval_rule_name": null,
    "required_by": null,
    "job_id": 42,
    "url": "https://ansilume.example.com/approval/view?id=9"
  }
}
```

- `event` is the event, `severity` its severity (`info`, `warning`,
  `error` or `critical`), `occurred_at` the Unix time it happened.
- `event_id` identifies the event: every template that the event reaches
  gets the same ID, and no other event has it.
- The objects are those of the event's group: a job's event carries `job`
  and `project`, a workflow event `workflow`, `step` and, while its step
  waits for one, `approval`; an approval event `approval` and `job`; a
  project's event `project`, a schedule's `schedule`, a runner's `runner`,
  and `webhook.invalid_token` the call with the unknown token (`trigger`).
  The job and the approval request of a workflow step's run carry `workflow`
  and `step` too, but only to a template whose user may see the run's
  workflow.
- IDs and counts are numbers; a value that the event does not have is
  `null`. `url` links the object's page.

web/openapi.yaml documents the request (`webhooks`, `notificationEvent`)
and every object (`NotificationEventPayload` and the schemas it refers to).

### Verifying the signature

A receiver checks each request before it reads it:

1. Take `t` and `v1` from `X-Ansilume-Signature`.
2. Refuse the request when `t` is more than 300 seconds away from your
   clock: it is an old request sent again.
3. Compute the HMAC-SHA256 of `<t>.<body>` with your signing secret as the
   key, where body is the request body as it arrived, before any JSON
   parsing, and write it as lower-case hexadecimal.
4. Compare it with `v1` in constant time; refuse the request when they
   differ.

In PHP:

```php
<?php

function verifyAnsilumeSignature(string $secret, string $header, string $body, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key] = $value;
    }
    $time = $parts['t'] ?? '';
    if (!ctype_digit($time) || abs(time() - (int)$time) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $time . '.' . $body, $secret);

    return hash_equals($expected, $parts['v1'] ?? '');
}

// verifyAnsilumeSignature($secret, $_SERVER['HTTP_X_ANSILUME_SIGNATURE'] ?? '', file_get_contents('php://input'))
```

In Python, with the body as bytes (in Flask `request.get_data()`):

```python
import hashlib
import hmac
import time


def verify_ansilume_signature(secret: str, header: str, body: bytes, tolerance: int = 300) -> bool:
    parts = dict(part.strip().split("=", 1) for part in header.split(",") if "=" in part)
    timestamp = parts.get("t", "")
    if not (timestamp.isascii() and timestamp.isdigit()) or abs(time.time() - int(timestamp)) > tolerance:
        return False
    expected = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected.encode(), parts.get("v1", "").encode())
```

Use Send Test to try a receiver: a test is signed as a delivery is, with
sample values.

## Channels of your own

A channel of your own is a PHP class that sends the messages of its
templates, defined beside the built-in channels. The API and the template
form take its templates, and Ansilume checks, shows and delivers them as
those of a built-in channel. The form has no panel of fields for such a
channel: it edits the config as a JSON object, names the definition's
required keys and secrets, and shows every secret as `$encrypted$`, which
keeps the stored value when it is saved unchanged.

### The class

The class implements `app\services\notification\ChannelInterface`:

```php
<?php

declare(strict_types=1);

namespace app\custom;

use app\components\OutboundHttpClient;
use app\models\NotificationTemplate;
use app\services\notification\ChannelInterface;

final class AcmePagerChannel implements ChannelInterface
{
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        /** @var OutboundHttpClient $http */
        $http = \Yii::$app->get('outboundHttp');
        $http->request(
            'POST',
            (string)$config['endpoint'],
            ['Content-Type: application/json', 'Authorization: Bearer ' . $config['api_key']],
            (string)json_encode(['title' => $subject, 'text' => $body])
        );
    }
}
```

- `send()` throws when the message did not go out. The notification
  worker retries a delivery whose error is an `OutboundHttpException` of a
  connection that failed, a timeout, or a 408, 425, 429 or 5xx answer, and
  any other error; it fails the delivery for any other answer. Its error is
  kept with the delivery, audited and logged, so it must hold no secret.
- Send every request through the component `outboundHttp`
  (`OutboundHttpClient`): it sends only to http and https URLs, follows no
  redirect, always verifies TLS (`OUTBOUND_CA_BUNDLE` adds CAs), and its
  errors name neither the URL, a header nor the answer.
- Mark every parameter that carries a secret, such as a token, a URL with
  one or a header, `#[\SensitiveParameter]`, so a stack trace in a log or
  on an error page leaves its value out.
- A class that also implements `DeliveryChannelInterface` gets each
  delivery through `sendDelivery()`, with the delivery's ID, the same on
  every retry, as the webhook channel's `X-Ansilume-Delivery`.
- A class that sends nothing for some events also implements
  `SelectiveChannelInterface`, whose `sendsEvent()` says whether it sends
  anything for an event. A template of the channel then gets no delivery of
  such an event and no audit entry for it, as for an event it does not
  listen to, and Send Test sends the template's first event that the
  channel sends. PagerDuty does so for `workflow.step_started` and
  `workflow.step_succeeded`, which never trigger an incident.
- The notification queue carries only the ID of each delivery, so your
  class never passes through the queue.

### The definition

`config/notification-channels.php` returns the channels of your own, each
name with its definition:

```php
<?php

return [
    'acme' => [
        'label' => 'Acme Pager',
        'class' => app\custom\AcmePagerChannel::class,
        'requiredKeys' => ['endpoint', 'api_key'],
        'secretKeys' => ['api_key'],
        'boundTo' => ['api_key' => 'endpoint'],
        'urlSchemes' => ['endpoint' => ['https']],
        'destinationKeys' => ['endpoint', 'api_key'],
    ],
];
```

| Setting | What it is |
|---|---|
| `label` | Required: the channel's name in the form and on the pages. |
| `class` | Required: the class, which implements `ChannelInterface`. Yii's container makes it, so container definitions can set its properties. |
| `requiredKeys` | The config keys that must hold a value: `api_key is required for Acme Pager.` |
| `secretKeys` | The keys whose values are secrets: the pages and the API show them as `$encrypted$`, in the config of every template, and a save that sends `$encrypted$` back keeps the stored value. |
| `secretMaps` | The keys whose every value is a secret, as the webhook's `headers`: the names are shown. |
| `boundTo` | A secret key or map, and the key whose stored value it goes along with: `$encrypted$` keeps the stored secret only while that key keeps its own (`api_key: endpoint changed; enter the real value.`). Bind every secret that goes to an address of the config, so that nobody can send a stored secret to an address of their own. |
| `urlSchemes` | A key that holds a URL, and the schemes it may use: `http`, `https` or both (`endpoint must be an https URL.`). |
| `destinationKeys` | The keys that say where the channel delivers: a change of one makes the editor the user the template delivers as. Without the setting, every key does. |
| `configRules` | A class that implements `ChannelConfigRules`, whose static `refusals()` returns what else is wrong with a config, one message per error. |

A channel's name is a lower-case letter, then up to 31 lower-case
letters, digits, `-` or `_`, and none of the built-in ones (`Notification
channel "webhook" is built in: give your channel another name.`). A
definition that is wrong, or a file that returns no array or that PHP
cannot parse, stops Ansilume when it starts: every console command ends
before it runs, so the app (whose start runs the migrations), the
queue-worker and the notification-worker do not come up, the
schedule-runner runs nothing, the health check fails, and the web answers
every request with a 500. The log of each container (`docker compose logs
app`) shows the message, which names the channel and what is wrong, such as
`Error: Notification channel "acme": its class app\custom\AcmePagerChannel does not exist.`;
the app's start follows it with `[entrypoint] ERROR: database migrations failed`.
Fix the file and start the services again. Take a channel out of
the file only together with its templates: a template of a channel that
Ansilume does not have delivers nothing, and its page shows every value of
its config as `$encrypted$`.

### Adding it to your installation

In a source installation started from `docker-compose.yml`, or one the
deploy role builds from source (without `ansilume_use_prebuilt_images`),
the containers run the code of the checkout: put the class under `custom/`
(the namespace `app\custom` maps to it: `custom/AcmePagerChannel.php`) and
the definition in `config/notification-channels.php`, which git ignores,
then restart the services that run Ansilume's code:

```bash
docker compose restart app queue-worker notification-worker schedule-runner
```

With the prebuilt images, an installation the quickstart made from source
included (it runs the images of `docker-compose.prebuilt.yml`, which do not
see the checkout), build an image of your own on Ansilume's that adds both,
with the tag of the release you run:

```dockerfile
FROM ghcr.io/ansilume/ansilume:latest
COPY custom/ /var/www/custom/
COPY notification-channels.php /var/www/config/notification-channels.php
```

Run the services `app`, `queue-worker`, `notification-worker` and
`schedule-runner` from it, with a compose file of your own beside
`docker-compose.prebuilt.yml` that sets their `image`, and build it again
on every update. There is no volume for code: a mount that adds classes
would let whoever may write to it run code in Ansilume.

## The API

| Endpoint | Permission | What it does |
|---|---|---|
| `GET /api/v1/notification-templates` | `notification-template.view` | The templates, newest first, 25 per page. |
| `GET /api/v1/notification-templates/{id}` | `notification-template.view` | One template. |
| `POST /api/v1/notification-templates` | `notification-template.create` | Creates a template; the caller is the user it delivers as. |
| `PUT /api/v1/notification-templates/{id}` | `notification-template.update` | Changes a template; a field left out keeps its value. |
| `DELETE /api/v1/notification-templates/{id}` | `notification-template.delete` | Deletes a template, with its deliveries. |
| `POST /api/v1/notification-templates/{id}/test` | `notification-template.update` | Send Test: `{"sent": true}`, or `502` with `Test failed: ` and the channel's error. |
| `GET /api/v1/notification-templates/{id}/deliveries` | `notification-template.view` | The template's deliveries that the caller may see, newest first, 25 per page. |

A template's fields:

- `name`, `description`, `channel`, `subject_template`, `body_template`;
- `config`: the channel's config, every channel secret as `$encrypted$`,
  which a `PUT` sends back to keep the stored value;
- `events`: a list of event names; a request may send them as
  comma-separated text too;
- `level`: `all` or `essential`;
- `workflow_scope`: `all` or `selected`;
- `workflow_template_ids`: the workflow templates of the selection that the
  caller may see. A `PUT` replaces those, and those the caller may not see
  stay; `workflow_templates_hidden` counts them;
- `id`, `created_by`, `created_at`, `updated_at`, and, read-only,
  `delivers_as` and `delivers_as_inactive`.

A request is refused with `422`, and nothing is written, for a workflow
template the caller may not see (`Workflow template(s) not found: #41.`),
for events of another form
(`events must be a list of event names, or the names as comma-separated text.`),
for workflow template IDs of another form
(`workflow_template_ids must be a list of workflow template IDs.`), and for
what the channel's rules refuse, such as
`mention may not be @all, @channel or @here, which notify everyone in the channel.`

A delivery's fields: `id`, `event`, `kind` (`message`, `status` for the
status post of a run, `reply`), `status`, `attempts`, `last_error`, the
objects it is about (`workflow_job_id`, `job_id`, `approval_request_id`,
`project_id`, `job_template_id`), `next_attempt_at`, `created_at`,
`updated_at` and `finished_at`. A delivery is listed only when the caller
holds the permission of its event's group and of each object it names, and,
where a team restricts the caller, may see those objects; `meta.hidden`
counts the others. No answer shows what a delivery's event carried, or the
post it made.

[web/openapi.yaml](../web/openapi.yaml) describes every field and answer,
with examples for a Mattermost template and a signed webhook.

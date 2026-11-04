# Workflow notifications, a Mattermost channel and hardened delivery

Date: 2026-11-03. Agreed with the product owner in chat on 2026-11-03; the
decisions they made are under **Decisions**, the ones the designer made
under **Designer's calls**.

## Intent

Operators want to follow their workflows in their team chat, Mattermost
first, without opening Ansilume:

- which step runs next;
- whether a step succeeded or failed;
- whether the run waits for someone: an approval, or a resume after a
  pause step;
- how the run ended.

The same events serve every channel (email, Telegram, Teams, Slack,
PagerDuty, webhook), not only Mattermost. The system stays open for
integrations of one's own.

While mapping the code, a vulnerability in the released notification
channels came up. Its fix is part of this work, because the new channel is
built on the same HTTP layer.

## Decisions (product owner)

1. **Mattermost has a channel of its own**, `mattermost`, not the Slack
   channel used for Mattermost. "Do it properly."
2. **Its only configuration is a Mattermost server URL plus a bot token**
   (REST API v4). There is no incoming-webhook mode for Mattermost.
3. **No approving in the chat**, not even as an option: the bot posts to a
   group. Messages link to the approval page; the decision stays in
   Ansilume.
4. **No Apprise.**
5. **Open for one's own integrations**: the signed webhook with a
   documented payload, and channel classes of one's own registered by
   configuration.
6. **One Ansilume installation per customer.** Team boundaries inside an
   installation still hold: a template delivers only what the user it
   delivers as may see. With no teams in use, nothing changes.
7. **Routing:**
   - each template has an optional filter "for these workflows" (empty:
     all);
   - each template has a level, "everything" or "only failures, waits and
     the end".
8. **Delivery through a queue** with retries, never in the request or
   process that caused the event.
9. **Fix the leak** found in the existing channels together with this work.

## Goals

- New workflow events for every channel: step started, step succeeded, a
  run waits (approval or resume), plus the existing launched, step failed,
  succeeded, failed and canceled.
- A `mattermost` channel. It keeps one status message per workflow run,
  edited in place as the run moves on, and posts the details as replies
  in that message's thread.
- Asynchronous, retried and durable delivery that survives an empty Redis,
  a worker restart and an update.
- A template delivers only events its identity may see (team scoping).
- One hardened outbound HTTP client for every channel and the outbound
  webhooks:
  - only http and https;
  - no redirects to other schemes;
  - no response content in errors.
- Everything is available through the REST API and the web UI, documented,
  and covered by tests: unit, integration, and E2E for the UI.

## Non-goals

- Approving, rejecting, resuming or launching from a chat.
- Apprise or another notification aggregator.
- An incoming-webhook mode for Mattermost.
- Notifications about a job waiting for a runner (Queued).
- Encrypting channel secrets at rest. They stay as today: plain JSON in the
  database, shown as `$encrypted$`. This is a follow-up for all channels
  together.

## Anti-goals

These would make it a failure even if it worked:

- A notification slows down or breaks a workflow, a job completion, a
  runner request or a vote. Delivery errors never reach the engine.
- A message carries a secret: extra vars, survey answers, credentials,
  tokens, repository URLs with credentials, or job output.
- A template shows a user what they may not see in Ansilume.
- The channel floods: one message per step and status line instead of one
  status message per run with a thread.
- An operator can send a stored bot token to a server of their choosing.

## Current state

From the code, as of v2.14.0.

**Templates.** `notification_template` has these columns:
- `name`, `channel` (email, slack, teams, webhook, telegram, pagerduty);
- `config` (plain JSON);
- `subject_template`, `body_template`;
- `events`: comma-separated, `varchar(512)`, while the model allows 1024;
- `created_by`, `created_at`, `updated_at`.

Templates are global: no filter, no team scoping. Operators hold
`notification-template.create`/`update`; admins also hold `delete`.

`created_by` is a safe, mass-assignable attribute on the web update form
(`models/NotificationTemplate.php` rules) and is never updated on edit.

**Dispatch.** `NotificationDispatcher::dispatch(string $event, array
$payload)` loads every template on each event and flattens the payload
into `{{ dot.keys }}` (`TemplateRenderer`). It then sends one template
after another, synchronously, in the caller's process:
- php-fpm for the web UI, the API, runner reports and triggers;
- the schedule-runner for the maintenance run;
- the queue-worker for project syncs.

Each send uses curl with a 10 s timeout and writes one
`notification.dispatched` or `notification.failed` audit row. Nothing is
retried. `sendSingle()` serves Send Test. The channel map is hard-coded in
`NotificationDispatcher::init()`. `ChannelInterface::send(): void`
returns nothing.

**Events today (18):**
- job: launched, succeeded, failed, canceled;
- workflow: launched, succeeded, failed, canceled, step_failed;
- approval: requested, approved, rejected;
- schedule.failed_to_launch;
- runner: offline, recovered;
- project: sync_failed, sync_succeeded;
- webhook.invalid_token.

Workflow payloads are thin: `workflow{id, status, template_id,
template_name}`, plus `step{id (the execution id), job_id}`. They carry
no URL, step name or position. Approval events carry no workflow context
and no URL.

**Workflow engine** (`WorkflowExecutionService`):
- `executeStep()` writes an execution row and runs `WorkflowStepDispatcher`.
  A job step launches a job. An approval step writes a placeholder job and
  its request. A pause step waits for `resume()`.
- `endStep()`/`closeStep()` end a step with a conditional UPDATE, so only
  one caller moves it on.
- `WorkflowStepRouter::route()` picks the next step.
- `completeWorkflow()` ends the run.
- `WorkflowCancellationService::cancelRun()` sends `workflow.canceled`
  before it ends the running steps.
- `resume()` and `failStep()`, a step that could not start, send no step
  event.
- Waiting states are derived, never stored: `WorkflowRunStates::forRun($run,
  Viewer::of($userId))`, banner texts in `services/runstate/Texts.php`.
- Every emission point is after a commit and holds no row lock.

**Links.** The run page is `/workflow-job/view?id=N`, the job page
`/job/view?id=N`, the approval page `/approval/view?id=N`. The absolute
base is `params.appBaseUrl`, which falls back to `http://localhost:8080`
when `APP_URL` is unset. In the console app, which includes queue workers,
`Url::to`/`createUrl` throw.

**Queue.** There is one yii2-queue 2.3.8 Redis queue (channel
`ansilume-queue`, ttr 3600, attempts 1). It carries one job class,
`SyncProjectJob`, which `AllowlistQueueSerializer::ALLOWED_CLASSES`
allows (max depth 8). One queue-worker process runs it
(`WorkerController`), and project syncs can take minutes. A driver retry
comes only after the TTR, and a message redelivered after a crash is
dropped silently.

**HTTP.** These channels pass the user's URL to curl with no protocol
limit and no URL check:
- Slack, Teams, Webhook, Telegram and PagerDuty use curl;
- the outbound webhooks (`WebhookService`) use PHP streams. They follow
  redirects (a 302 turns the POST into a GET), take any status line
  containing "2" for success, and log PHP's warning text, which holds the
  full URL.

### The vulnerability (fixed by this work)

The HTTP channels accept URL schemes other than http and https, and their
error text includes the first 200 bytes of the response. Through **Send
Test**, a user who may create or update notification templates (operators
by default) can make the app container read local resources. The start of
what was read then appears in the test flash, the API's 502 answer and
the `notification.failed` audit row.

Verified with a harmless file in the dev container. Mattermost's own
webhook errors also echo the secret hook ID, which then lands in the same
places.

### Mattermost API

Facts from the Mattermost server source v12.0.1 and the docs:

- **Auth:** `Authorization: Bearer <bot token>`. A bot token is a full
  session of the bot user, valid until revoked. `GET /api/v4/users/me`
  returns `is_bot`.
- **Channel:**
  - `GET /api/v4/channels/{id}`, or by name with
    `GET /api/v4/teams/name/{team}/channels/name/{channel}`; IDs survive a
    rename, URL names do not;
  - membership: `GET /api/v4/channels/{id}/members/me`. The bot must be a
    member to edit its own posts: `post:all`/`post:channels` grant only
    create.
- **Posts:**
  - create with `POST /api/v4/posts` (`channel_id`, `message`, `root_id`
    for replies, `props.attachments` with `color` `#RRGGBB`, `title`,
    `title_link`, `fields`), answered `201` with the post;
  - edit with `PUT /api/v4/posts/{id}/patch`. It replaces `message` and,
    if sent, the whole `props`. Never use `PUT /api/v4/posts/{id}`, which
    blanks the fields it is not sent;
  - never send `from_bot`; the server sets it for bots;
  - `?set_online=false` on creates.
- **Errors** carry an `id`. Switch on it, not only on the status:
  - patch of a deleted post: `403`;
  - create in a nonexistent channel: `403`;
  - reply to a deleted root: `400 api.post.create_post.root_id.app_error`;
  - archived channel: `400 can_not_post_to_deleted`;
  - `ServiceSettings.PostEditTimeLimit` (admin setting, default off)
    fails patches of older posts with `400`;
  - `401` "Invalid or expired session": token revoked or expired, or bot
    disabled when its owner was deactivated;
  - on 12.0.1 clusters with read replicas, a patch right after the create
    can get `403` (MM-70867): retry before falling back.
- **Dedup:** `pending_post_id` deduplicates creates for 30 s only.
- **Limits:**
  - rate limiting is off by default; when on, `429` with a plain-text body
    and `Retry-After` / `X-RateLimit-*`;
  - a message is at most 16383 characters, the request at most 300000
    bytes.
- **Notifications:** edits never notify. Replies notify only thread
  followers (Collapsed Reply Threads is on by default) and mentioned users.
  `?silent=true` exists from 11.10.
- **Markdown and mentions** are parsed in `message` and in the attachments'
  title, text, pretext, footer and fields.
- **Version floor:** 10.11 ESR and later.

## Design

### 1. Hardened outbound HTTP (the vulnerability fix)

One client component serves every channel and the outbound webhooks. It
replaces the per-channel curl code and the streams in `WebhookService`.
The client:

- accepts only `http` and `https`, for the request and for redirects
  (`CURLOPT_PROTOCOLS_STR`, `CURLOPT_REDIR_PROTOCOLS_STR`), and follows no
  redirect;
- sets a connect timeout and a total timeout, keeps TLS peer and host
  verification on, and caps the response body it reads;
- can use a CA bundle file named by an environment setting
  (`OUTBOUND_CA_BUNDLE`) for self-hosted servers with an internal CA. There
  is never a "skip verification" option;
- returns status, headers and body to its caller, because Mattermost needs
  the post ID. Its errors are built from message constants and say only:
  - the HTTP status;
  - for Mattermost, the API error `id` and `X-Request-ID`;
  - for curl, the error class (could not connect, timed out, TLS failed).

  They never carry the URL, a token, a header or response content;
- marks its URL, token and header parameters `#[\SensitiveParameter]`;
- does not use `curl_close()`.

The template model also validates every URL in a channel config at save:
- `http`/`https` only;
- `https` only for Mattermost, as the bot token is a full session.

A stored template with another scheme fails its deliveries with a clear
message, and must be fixed before it can be saved again.

`WebhookService`: success is a `2xx` status read from the response, and it
follows no redirect. It logs through `ErrorText`, never PHP's warning text.

Every existing channel gets regression tests:
- a `file:` URL and other schemes are refused without being read;
- an error never contains response content.

The release notes name it as a security fix.

### 2. Delivery: outbox, notification queue, notification worker

**Outbox.** A new table `notification_delivery` holds one row per template
and event:
- `id`;
- `notification_template_id` (FK, cascade);
- `event`;
- `workflow_job_id` (nullable FK, cascade; the thread key);
- `kind`: `message`, `status` (the run's status message) or `reply`;
- `variables`: a JSON snapshot taken at event time, flat strings, no
  secrets;
- `status`: `pending`, `sending`, `delivered`, `failed`, `skipped` or
  `superseded`, as constants;
- `attempts`, `next_attempt_at`, `claimed_at`;
- `last_error`, from message constants;
- `remote_id`: the post a delivery created;
- `created_at`, `updated_at`, `finished_at`.

A thread table `notification_thread` holds one row per (template, run),
unique on those two, with FKs cascading:
- `root_post_id`;
- `channel_id`;
- the server the post lives on (to notice a changed server).

**Producer.** `NotificationDispatcher::dispatch($event, $payload)` keeps
its signature: producers and the test doubles depend on it. Inside, it:

1. selects the templates that listen to the event, pass the workflow
   filter and the level (section 4), and whose identity may see the event
   (section 4);
2. snapshots the variables: occurred-at, absolute links from `appBaseUrl`
   (console-safe, no `createUrl`);
3. inserts the outbox rows;
4. pushes one queue message per row, after the producer's transaction has
   committed (the emission points already run after commit).

A failed push is caught and logged with `DbErrorSummary`/`ExceptionLogText`
(yii2-redis puts the command in its message). The sweeper delivers later.
`dispatch()` never throws into its caller. `JobCompletionService` stays
safe if a notification fails: the workflow still advances.

**Queue and worker.** A second queue component carries notifications on
its own Redis channel, so they never wait behind project syncs. A new
long-running service runs it in `docker-compose.yml`,
`docker-compose.prebuilt.yml` and the deploy role, with:
- the www-data user, a health check and the database-connection handling
  of the queue worker (CLAUDE.md's process rules);
- an entry in `IdleDatabaseConnectionTest::PROCESSES`;
- its own heartbeat key, so the project page's sync "stuck" detection is
  unaffected.

Prefer reusing `WorkerController` with an option for the queue component
over a second controller.

**The job.** `DeliverNotificationJob` carries only `deliveryId` and is
added to the serializer allowlist. It:

1. claims its row with a conditional UPDATE (`pending` → `sending`, due
   now). If no row changed, it returns: a duplicate push, or another worker
   got it;
2. for a thread row, requires that every earlier row of the same (template,
   run) is finished, or re-pushes itself with a short delay. The status
   post therefore always exists before an edit or a reply;
3. re-checks the identity's visibility (section 4) and marks the row
   `skipped` if it was lost;
4. renders and sends through the channel;
5. records the outcome.

It never rethrows a channel error, because `QueueLogBehavior` would log
its message.

**Retries.** The job re-pushes with a delay; it does not use the driver's
retry, which waits the whole TTR:
- backoff: 30 s, 2 min, 10 min, 30 min, 1 h, 2 h, 4 h; at most 8
  attempts, configurable on the component;
- transient: connect or timeout errors, `408`, `429` (wait `Retry-After`
  when given), `5xx`;
- permanent, failed at once: `400`, `401`, `403`, `404`, `413`, an invalid
  config, an unknown channel. Mattermost's fallbacks come first
  (section 5);
- the message is pushed with a TTR of about 2 minutes, well above the
  longest delivery (at most three HTTP calls with the client's timeouts).

**Sweeper.** A `MaintenanceService` task, cheap and batched, within the
shared budget:
- pushes `pending` rows that are overdue again (lost message, empty Redis,
  an old worker during an update);
- resets `sending` rows claimed longer than the TTR plus a margin to
  `pending`;
- purges finished rows older than 30 days.

**Send Test** stays synchronous for every channel, through `sendSingle()`
and the hardened client. For Mattermost it runs the checks of section 5
and posts a test message.

**Audit.** One `notification.dispatched` or `notification.failed` entry
per delivery's final outcome, never per attempt. Status edits that a later
edit superseded write none. Entries written by the worker carry no user;
the row says which template and event.

**Visibility of deliveries:**
- API: `GET /api/v1/notification-templates/{id}/deliveries`, paginated,
  newest first. It returns event, kind, status, attempts, the last error,
  the times, and the run or job it belongs to.
- Web UI: the template's page shows its last deliveries with the same
  fields.

### 3. Events and payloads

New events. They belong to the workflow group, are offered to every
channel, and fire once per transition, only after the conditional update
that made the transition returned true:

| Event | When | Severity |
|---|---|---|
| `workflow.step_started` | A step started: a job launched, an approval requested, or a pause reached. Fires at the end of `executeStep()`, after dispatch and settle, while the execution still runs | info |
| `workflow.step_succeeded` | A step ended successfully (`endStep` after `closeStep`). Also a resumed pause step (`resume()`) and an approved approval step | info |
| `workflow.waiting` | The run waits for a person: an approval (approval step, or gated job step: the job is `pending_approval`) or a resume (pause step). Derived with the same code as `WorkflowRunStates`, not a second copy of its rules | warning |

Changed events:

- `workflow.step_failed` also fires for a step that could not start
  (`failStep`). Steps that a cancel ends send none: the run's
  `workflow.canceled` covers them.
- `workflow.canceled` is sent after the cancel has ended the running steps,
  so a status rendered from it is final.

Payload of every workflow event, in addition to today's keys:
- `workflow.url`;
- `step.execution_id`, `step.workflow_step_id`, `step.position`,
  `step.name`, `step.type`;
- `step.waiting_for` (`approval`, `resume`) and `approval.url` where they
  apply;
- the run's counts (steps done, total);
- the reason in the words of `services/runstate/Texts.php`, so a message
  says what the run page says.

Approval events of a workflow step carry the run's ID, name and URL. They
carry them only where the identity may see the workflow (gated job steps
can be visible through their project while the workflow is not). Job
events of workflow step jobs carry the run's ID.

Wherever events are listed or priced, the new events are added:
- `eventGroups`, `eventSeverity`, the presets;
- PagerDuty: info events never trigger an incident;
- `NotificationTestVariables`, the OpenAPI event list (fix its stale
  "(15)"), the docs.

The `events` column widens to 1024 (migration), matching the model rule.

### 4. Template options: workflow filter, level, identity

**Workflow filter.** A pivot table `notification_template_workflow_template`
(FKs, cascade on the template) holds the workflow templates a template is
for. Empty means all.

The filter applies to:
- workflow events;
- the approval and job events that belong to a workflow run.

Events unrelated to workflows reach the template as its event list says.
The form and the API offer only workflow templates the editor may view.
IDs are read with `SubmittedId`. Add the field to
`SubmittedNumbersApiTest::OPERATIONS` and
`SubmittedNumbersWebFormTest::FORMS`.

**Level.** A column with constants:

- `all`;
- `essential`: only `workflow.step_failed`, `workflow.waiting` and the
  run's end (succeeded, failed, canceled).

The level applies to workflow events only. Default for new templates:
`all`.

**Identity: "delivers as".** A new column, `delivers_as` (user ID). It
holds the user who last changed what the template delivers or where to:
- channel, destination config, events;
- workflow filter, level.

This mirrors `ScheduleRunAs`. The column is set by the server, never
mass-assignable, and audited as `{from, to}`. `created_by` stays as the
creator, made unsafe (not mass-assignable). The migration sets
`delivers_as = created_by` for existing templates.

A template delivers an event only when its identity is active, holds the
permission, and passes the access check of the object.

| Events | Permission | Access check |
|---|---|---|
| workflow | `workflow.view` | `canViewWorkflowTemplate` |
| job | `job.view` | `canAccessJob` |
| approval | `approval.view` | `canViewApprovalRequest`; workflow context only with workflow visibility |
| project | `project.view` | `ProjectAccessChecker::canView` |
| `schedule.failed_to_launch` | `job.launch` | the job template's project |
| `runner.*`, `webhook.invalid_token` | the admin role | installation-level events |

The rules:
- always gate on `Viewer::of($id)->may(...)` first:
  `canViewWorkflowTemplate` alone checks neither status nor RBAC;
- the check runs at dispatch time and again at delivery time;
- a template whose identity is inactive delivers nothing, and its page
  says why.

With no team-restricted project, every active user sees everything, so
nothing changes for installations without teams.

### 5. The Mattermost channel

**Config:**
- `server_url` (https), `bot_token` (secret), and the channel: team name
  plus channel name, or a channel ID;
- an optional `mention`: a user or group mention added to attention
  replies, validated; never `@all`, `@channel` or `@here`.

The names are resolved to the channel ID when the template is saved or
tested. Save stores the ID and the names; deliveries use the ID.

**Secret binding.** Extend `NotificationConfigSecrets`: a `bot_token`
placeholder is kept only while `server_url` keeps its stored value, as the
header-to-url rule does for header maps. Otherwise the stored token would
go to whatever server the editor enters. Mark every parameter that carries
the token `#[\SensitiveParameter]` and extend `SensitiveParametersTest`
(`MARKED`, `MUST_BE_MARKED`).

**Send Test**, synchronous, with a readable result for each:

1. `GET /users/me` must be a bot. A personal token is refused: it carries
   a human's rights.
2. The channel must exist, and the bot must be a member. Without
   membership every status update would fail.
3. Post a test message.

Show only the status, the Mattermost error id and `X-Request-ID`.

**Workflow runs** (templates subscribed to workflow events):

- **One status post per (template, run)**, created by the run's first
  delivered workflow event and recorded in `notification_thread`. Every
  later workflow event edits it (`PATCH`).
- The status post is **rendered from the run's current state at delivery
  time**, with `WorkflowRunStates::forRun($run, Viewer::of($identity))`.
  That makes edits idempotent and order-safe, and pending older status
  rows of the same thread can be marked `superseded`. It shows:
  - the run's name and status, colour-coded;
  - each step with its state and position;
  - the current or next step, and what the run waits for, with the link;
  - the result and the duration;
  - links to the run.
- **Thread replies** (`root_id`) for the run's events:
  - level `all`: step started, step succeeded, step failed, waiting, end;
  - level `essential`: step failed, waiting, end.

  Replies are short: the step, the result, and a link.
- **Attention.** Edits never notify, and replies reach only thread
  followers. So the replies for `workflow.waiting`, `workflow.step_failed`,
  `workflow.failed` and `workflow.canceled` start with the configured
  `mention`, when one is set.
- **Fallbacks:**
  - a patch answered `403` (post deleted, bot removed) or `400` edit time
    limit, retried once for the replica-lag case, makes the channel post a
    new status post and record it;
  - a reply answered `400 root_id` (root deleted) creates a new root;
  - an archived channel, `401` or a missing channel fail permanently. The
    message names the causes: token revoked or expired, bot disabled when
    its owner was deactivated, bot not in the channel.

  Creates send `pending_post_id` = the delivery ID, so a retry within 30 s
  does not post twice. A duplicate after a crash between "posted" and
  "recorded" is accepted and documented.

**Other events** (jobs outside workflows, projects, schedules, runners,
approvals outside workflows): one post per event, with an attachment
coloured by severity and the subject and body of the template.

**Formatting.** Use Markdown and `props.attachments` (colours as
`#RRGGBB`, `title_link` absolute). Escape every user-controlled value:
workflow, template, step and user names, and error snippets. Escape
Markdown and neutralise `@`, so no name can mention a channel. Stay well
below 16383 characters and truncate with a link to the run. Never include
job output, extra vars or survey answers.

### 6. Extensibility

**Signed webhook.** The `webhook` channel gets:

- an optional `signing_secret` (secret, bound to `url` like the bot
  token);
- a `payload` option: `template` (today's rendered body, the default for
  existing templates) or `event` (the documented JSON);
- signed requests carry `X-Ansilume-Signature: t=<unix time>,v1=<hex
  HMAC-SHA256 of "<t>.<body>">` and `X-Ansilume-Delivery: <delivery id>`,
  stable across retries.

The `event` payload:
- `event`, `event_id` (`{run}:{execution}:{event}` or similar, unique per
  transition), `occurred_at`, `severity`;
- the objects `workflow`, `step`, `job`, `approval`, `project`, shaped like
  the REST API's resources, with links.

Document it in a new `docs/notifications.md`, with a schema in
`web/openapi.yaml` and a verification example.

**Channel classes of one's own.** The channel map moves from
`NotificationDispatcher::init()` into the component's configuration, in
`config/services.php` and `config/test.php`, never the database.

A channel is described by a definition the model, the secrets handling and
the form read, instead of today's constants:
- label;
- required config keys;
- secret keys and secret maps, with the key each is bound to;
- URL keys and their allowed schemes.

A channel that keeps message state, like Mattermost, implements a richer
contract than `send(): void`: it creates, edits and replies, and returns
references. Stateless channels keep `send()`.

The form shows a raw JSON config editor for a channel it has no panel for.
The OpenAPI spec documents `channel` as the built-in values plus
configured ones. Third-party classes must mark their own sensitive
parameters; the docs say so. Queue messages stay ID-only, so custom
classes never enter the serializer allowlist.

### 7. API, UI, RBAC

- **REST API.** `POST`, `PUT` and `GET /api/v1/notification-templates`
  take and return the new fields:
  - `workflow_template_ids` as a JSON array of IDs;
  - `level`;
  - `delivers_as`, read-only;
  - the Mattermost and webhook config, with secrets as `$encrypted$`.

  `events` accepts a JSON array as well as today's comma-separated string.
  Plus the deliveries endpoint. OpenAPI: schemas, examples, enums, the
  event list.
- **Web UI.** The template form gets:
  - a Mattermost panel: server URL, bot token, team and channel or channel
    ID, mention;
  - the workflow filter as a multi-select of visible workflow templates;
  - the level;
  - for the webhook, the signing secret and the payload option.

  The view page shows "delivers as", the filter, the level and the last
  deliveries.
- **RBAC.** It stays as it is: operators create and update, admins also
  delete, viewers view. Scoping comes from `delivers_as`, not from new
  permissions.
- **E2E (Playwright).** Per role:
  - admin: create and edit a Mattermost template, validation errors,
    filter and level, the deliveries table;
  - operator: may create;
  - viewer: blocked.

  Send Test against a fake Mattermost server, if the stack can reach one;
  otherwise covered by integration tests.

### 8. Data model and migrations (forward-only)

- **`notification_template`:**
  - `events` widened to 1024;
  - new columns `level` and `delivers_as` (FK user, backfilled from
    `created_by`).
- **New tables:** `notification_template_workflow_template`,
  `notification_delivery` (indexes on status/next_attempt_at,
  template/created_at and workflow_job_id), `notification_thread`.
- **Existing templates:** keep their behaviour. They get level `all`, no
  filter, and the webhook payload `template`. Those of an operator now
  deliver only what that operator may see, which changes nothing without
  teams.

### 9. Operations

- **The new notification worker service** goes into both deployment paths
  and the deploy role. `bin/diagnose` and the quickstart know it; CLAUDE.md
  and `docs/` describe it.
- **`OUTBOUND_CA_BUNDLE`** for internal CAs: mount the bundle into the app
  and the notification worker. The docs give the compose and deploy-role
  forms.
- **`APP_URL`** must be set for correct links. The docs and `bin/diagnose`
  warn when it is the default.
- **Docs:**
  - a new `docs/notifications.md`: events, variables, channels, Mattermost
    setup (enable bot accounts; create the bot with the Member role; add it
    to the team and the channel; copy the token; the edit time limit and
    token lifetime caveats), filter, level, visibility, delivery and
    retries, the signed payload, channels of one's own;
  - a section in `docs/updating.md` for the release: queued delivery, the
    new service, the security fix, identity scoping of operator templates,
    audit entries without a user;
  - `docs/troubleshooting.md`, including the "a failed notification is not
    sent again" text, now wrong (`ErrorReportsDocsContractTest` pins it);
  - the README;
  - the stale claims found: Teams missing in the README, "15 events" in
    the spec, the `successful` status in Teams and the test variables.
  - `WorkflowStepsUpdateDocsContractTest::channelSecretsInWords()` must be
    limited to the channels of its release.

### 10. Testing

The rules of CLAUDE.md apply: 100 % coverage of new code, regression tests
that fail before the fix, the API tested by response codes and structure,
and E2E for UI changes. In particular:

- **The client:**
  - schemes and redirects;
  - timeouts and the cap on the response body;
  - no URL, token or body in errors, from logs to audit to answers;
  - the CA bundle setting.
- **Every channel:** a `file:` URL is refused (regression).
- **Delivery:**
  - the outbox and the job: claim, ordering per thread, retry
    classification, backoff, giving up, `superseded`;
  - the sweeper;
  - the allowlist;
  - `WorkerJobDbConnectionTest`'s provider;
  - the new process in `IdleDatabaseConnectionTest`.
- **The events:** each hook fires once per transition, loops included; no
  event for a refused transition; `step_failed` for `failStep`; cancel
  order.
- **Visibility:** each event family, inactive identities, the
  mass-assignment regression on `created_by`, the `delivers_as` updates.
- **Mattermost against a local fake server** (extend
  `HttpLoopbackServer`):
  - create, then patch, then reply;
  - 403, 400 and 401 handling, 429 with `Retry-After`;
  - the test checks;
  - mention escaping, truncation.
- **The webhook:** signature and payload against the documented schema.
- **API:** the new fields, the deliveries endpoint, floats and lists
  refused for the IDs.

## Designer's calls

Settled by the designer; a builder may refine them, not reverse them
without asking:

- A separate notification queue and worker service, not the shared
  queue-worker. It avoids minutes of latency behind project syncs and
  false "sync stuck" signals.
- Delivery identity is "who last changed what or where the template
  delivers" (`delivers_as`), as for schedules, not the literal creator:
  the creator could be overwritten through the form, and an editor would
  inherit it.
- Status posts are rendered from the current state at delivery time, not
  from the event snapshot.
- Attention goes through an optional mention in replies, not through extra
  top-level posts.
- Mattermost `server_url` must be https; other channels may use http or
  https.
- Mattermost 10.11 ESR is the minimum. `silent` is not used.
- The audit records one entry per final delivery outcome.
- Finished deliveries are kept for 30 days.
- A personal access token is refused for Mattermost: bots only.
- Channel secrets stay unencrypted at rest as today. A follow-up encrypts
  all of them together.

## Answers to the builder check

These settle what a builder reading this document asked. They take
precedence over anything above that reads otherwise.

1. **Status post and level.**
   - A run's status post is created by the run's first event that the
     template subscribes to and that its level lets through.
   - Once the post exists, every transition of the run refreshes it, so it
     never stays "running": every workflow event, plus the job and approval
     events of its steps, as long as the event passes the filter and the
     identity check. The producer writes a status-only row for that.
   - The event list and the level decide only which thread replies are
     posted, and whether a post is created at all.
   - A template subscribed only to failures shows nothing until a failure.
     Its post then stays current until the run ends.
2. **Job and approval events of a workflow run.**
   - On a Mattermost template they are thread replies, when subscribed, and
     they refresh the status post. They carry `workflow_job_id` and wait
     their turn in the thread's order.
   - When an approval of a workflow step is settled, by a vote or by a
     timeout, the producer writes a status-only row, so the post no longer
     says "waits for approval". The timeout itself still sends no approval
     event, as today.
   - An identity that sees the job but not the workflow gets plain posts
     without the run's context: no thread, no workflow name.
3. **Existing templates keep their behaviour, apart from team scoping.**
   - `runner.*` needs `runner-group.view`, which viewers and operators hold
     today.
   - `webhook.invalid_token` carries no team-scoped data and goes to any
     active identity, as today.
   - PagerDuty keeps alerting on every event it alerts on today; only the
     new info events (`workflow.step_started`, `workflow.step_succeeded`)
     never trigger an incident.
   - A template whose identity is inactive after the update delivers
     nothing, and this is never silent:
     - the template's page and its API resource show it
       (`delivers_as_inactive`);
     - `bin/diagnose` lists such templates;
     - the release notes say so. Saving the template makes the saver its
       identity.
4. **Saving does not reach Mattermost.**
   - Save validates the form only: https URL, a token, and a channel ID or
     team and channel names. An outage never blocks an edit.
   - Names are resolved, and the bot identity is checked (`is_bot`), by Send
     Test and by the first delivery after the token or the channel changed.
     The resolved channel ID and the result are stored with the template's
     server state, not in its editable config.
   - A personal token makes deliveries fail permanently, with a message
     saying that a bot token is needed.
   - Unit and integration tests inject the HTTP transport into the channel.
     The client's TLS and CA-bundle handling is tested against a local TLS
     server. E2E covers the form, not delivery.
5. **What viewers see.**
   - The template page, its API resource and the deliveries endpoint show
     the viewer only what the viewer may see. Filtered workflow templates
     and deliveries for objects the viewer may not see are left out, with a
     count such as "2 more you cannot see".
   - The filter has its own switch, `workflow_scope`: `all` or `selected`.
     A `selected` filter whose workflow templates were all deleted matches
     nothing; it never turns into "all".
   - On save, an editor may add or remove only workflow templates they can
     see. Stored ones they cannot see are kept as they are.
   - `workflow_template_ids` in `PUT` replaces the editor's visible part of
     the list.
6. **`webhook.invalid_token` is throttled** at the producer: at most one
   delivery per template per 5 minutes. The message says that further
   attempts are in the audit log. Rate-limiting the trigger endpoint itself
   is a follow-up.
7. **Channel classes of one's own in prebuilt images:** build an image
   `FROM` the Ansilume image that adds the class and a configuration
   override; the docs show how. Source installations edit the
   configuration. There is no mount for code: it would be a code-loading
   surface of its own.

## Left to the builder

- Class and file names, how `WorkerController` takes the queue component,
  and the exact backoff configuration names.
- The exact Markdown layout of the status post and of replies, within the
  rules above.
- Whether the client is a component or a service.
- How the pivot and the level are presented in the form.

## Follow-ups (not in this work)

- Encrypt all channel secrets at rest.
- Schedule `health/check-runners`, which no stack runs today; until then
  `runner.*` never fires.
- Have the quickstart write `COMPOSE_FILE` for installations made from
  source.
- Fix the OpenAPI response of `POST /api/v1/projects/{id}/sync`
  (`data.syncing` against `synced` in the code).

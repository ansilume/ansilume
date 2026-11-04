# Workflow notifications and Mattermost: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task by task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Workflow notifications for every channel, a `mattermost` channel
with one status post per run, queued and durable delivery, and the
hardened outbound HTTP that fixes the scheme leak of the existing channels.

**Architecture:**
- **HTTP:** one hardened HTTP client serves every channel and the outbound
  webhooks.
- **Delivery:** the dispatcher writes outbox rows, and a dedicated
  notification queue and worker deliver them with retries. A maintenance
  sweeper makes this durable without Redis.
- **Mattermost:** a threaded channel keeps a status post per (template,
  run), rendered from the run's current state.

**Tech stack:** PHP 8.5, Yii2, yii2-queue 2.3.8 (redis), MariaDB, curl,
Mattermost REST API v4, Playwright.

**Spec:**
`docs/superpowers/specs/2026-11-03-workflow-notifications-mattermost-design.md`.
Its section "Answers to the builder check" overrides anything earlier in
it.

## Global constraints

- **CLAUDE.md applies in full.** In particular:
  - strict types, no alignment spacing, PSR-12, PHPMD limits;
  - messages in class constants;
  - `#[\SensitiveParameter]` on every token, URL or header parameter
    (`SensitiveParametersTest` MARKED, MUST_BE_MARKED);
  - `DbErrorSummary`/`ErrorText`/`ExceptionLogText` for errors;
  - `SubmittedId`/`SubmittedInteger` for IDs from request bodies;
  - every feature in the API and `web/openapi.yaml`;
  - E2E for UI changes;
  - 100 % coverage of new code;
  - a regression test that fails before each fix;
  - both deployment paths (dev compose, prebuilt compose, deploy role).
- **No commits per task.** CLAUDE.md commits only on "PUSH IT". Where a
  task would commit, run its tests instead.
- **Only `http` and `https`** for every outbound request; Mattermost
  `server_url` must be `https`. TLS verification is never off.
  `OUTBOUND_CA_BUNDLE` (a file path) adds a CA bundle.
- **Client timeouts:** connect 5 s, total 15 s; response body read at most
  65536 bytes.
- **Retry backoff:** 30, 120, 600, 1800, 3600, 7200, 14400 s; at most 8
  attempts.
- **Time limits:** notification queue TTR 120 s; finished deliveries
  purged after 30 days; `webhook.invalid_token` throttled to one delivery
  per template per 300 s.
- **Mattermost:**
  - message at most 16383 characters: truncate below it with a link to the
    run;
  - colours `#RRGGBB`;
  - never send `from_bot`;
  - creates use `?set_online=false` and `pending_post_id` = the delivery
    ID;
  - edits use `PUT /api/v4/posts/{id}/patch`, never
    `PUT /api/v4/posts/{id}`;
  - minimum version 10.11 ESR.
- **No secret in any message, outbox row, queue message, log or audit
  entry:** extra vars, survey answers, credentials, tokens, job output,
  repository URLs with credentials. Queue messages carry only
  `deliveryId`.

## Review focus

Spec-implied cases most likely to bite, each pinned by a test in its
owning task:

1. A run that loops (a step ID leads back) delivers one status post and
   one reply per execution, never one reply per poll. Task 8:
   `testALoopPassFiresStepEventsOncePerExecution`.
2. Redis starts empty, or a worker crashes mid-delivery: the sweeper
   re-pushes pending rows and resets stale `sending` rows, and nothing is
   lost or posted twice within 30 s. Task 6:
   `testTheSweeperRepushesLostAndStaleDeliveries`.
3. An operator changes `server_url` and keeps the token placeholder: the
   save is refused, and the token never goes to the new host. Task 9:
   `testRegressionAStoredBotTokenNeverGoesToAChangedServer`.
4. A name with `@all`, Markdown or 20000 characters renders as plain text,
   mentions nobody and stays under 16383 characters. Task 9:
   `testUserTextCannotMentionOrBreakTheLayout`.
5. A template of an operator in team A never delivers team B's events,
   also when the operator edits an admin's template. Task 7:
   `testATemplateDeliversOnlyWhatItsIdentityMaySee`.

---

### Task 1: Hardened outbound HTTP client

**Files:**
- Create:
  - `components/OutboundHttpClient.php`
  - `components/OutboundHttpResponse.php`
  - `components/OutboundHttpException.php`
- Modify: `config/services.php`, `config/test.php` (component
  `outboundHttp`), `.env.example` (`OUTBOUND_CA_BUNDLE`)
- Test:
  - `tests/unit/components/OutboundHttpClientTest.php`
  - `tests/unit/services/notification/HttpLoopbackServer.php` (reuse;
    extend with per-path answers and redirects)
  - a local TLS test server for the CA-bundle case

**Interfaces:**
- **Produces:**
  - `OutboundHttpClient::request(string $method, #[\SensitiveParameter]
    string $url, array $headers = [], ?string $body = null):
    OutboundHttpResponse`;
  - public properties `?string $caBundle`, `int $connectTimeout = 5`,
    `int $timeout = 15`, `int $maxBodyBytes = 65536`;
  - `OutboundHttpResponse`: readonly `int $status`,
    `array<string, string> $headers` (lower-case names), `string $body`,
    plus `header(string $name): ?string`;
  - `OutboundHttpException extends \RuntimeException`:
    - readonly `string $kind`: one of `scheme`, `connect`, `timeout`,
      `tls`, `status`;
    - readonly `int $httpStatus` (0 when none);
    - readonly `?OutboundHttpResponse $response`, set for `status`;
  - message constants:
    - `SCHEME_REFUSED = 'The URL must use http or https.'`
    - `CONNECT_FAILED = 'Could not connect (curl error %d).'`
    - `TIMED_OUT = 'The request timed out after %d seconds.'`
    - `TLS_FAILED = 'The TLS connection failed (curl error %d).'`
    - `HTTP_STATUS = 'HTTP %d.'`
- **Consumes:** nothing.

- [ ] **Step 1: Write the failing tests**
  - `testOtherSchemesAreRefusedWithoutBeingRead`: a data provider with
    `file:///etc/hostname`, `gopher://127.0.0.1:1/`, `dict://127.0.0.1:1/`,
    `ftp://127.0.0.1/`. Each throws `kind === 'scheme'`, and the message
    is exactly `SCHEME_REFUSED`.
  - `testARedirectIsNotFollowed`: loopback answers `302 Location:
    file:///etc/hostname`. It throws `kind 'status'`, `httpStatus 302`.
  - `testErrorsNeverCarryTheUrlTheBodyOrAHeader`: the URL has a secret
    path and a secret header; the response body is `secret-body`. Assert
    that the exception message, the previous chain and the `Yii::info`
    lines contain none of them.
  - `testTheBodyIsCappedAtMaxBodyBytes`.
  - `testATimeoutIsReportedAsTimeout`, against a loopback that sleeps.
  - `testTheCaBundleIsUsedForTls`, against a local TLS server with a
    self-signed CA: it fails without the bundle (`kind 'tls'`) and passes
    with it.
  - `testA2xxAnswerReturnsStatusHeadersAndBody`.
- [ ] **Step 2: Run them and verify they fail.**
  Run: `docker compose exec -T app php -d memory_limit=1G
  vendor/bin/phpunit --no-coverage
  tests/unit/components/OutboundHttpClientTest.php`.
  Expected: FAIL (class not found).
- [ ] **Step 3: Implement the client.**
  - curl with `CURLOPT_PROTOCOLS_STR` and `CURLOPT_REDIR_PROTOCOLS_STR`
    set to `'http,https'`; `CURLOPT_FOLLOWLOCATION` false;
    `CURLOPT_CONNECTTIMEOUT`, `CURLOPT_TIMEOUT`.
  - A write callback that stops after `maxBodyBytes`; a header callback.
  - `CURLOPT_CAINFO` when `caBundle` is set.
  - The scheme is checked before `curl_init` (parse_url scheme,
    lower-case, in `['http', 'https']`).
  - No `curl_close`, no `CURLOPT_VERBOSE`.
  - Non-2xx throws `kind 'status'`, carrying the response.
- [ ] **Step 4: Run the tests and verify they pass.** Run the same
  command. Expected: OK.

### Task 2: Retrofit channels and webhooks; validate URL schemes; close `created_by`

**Files:**
- Modify:
  - `services/notification/SlackChannel.php`, `TeamsChannel.php`,
    `WebhookChannel.php`, `TelegramChannel.php`, `PagerDutyChannel.php`
  - `services/WebhookService.php`
  - `models/NotificationTemplate.php` (rules)
  - `controllers/NotificationTemplateController.php` (load without
    `created_by`)
- Test:
  - `tests/unit/services/notification/ChannelSchemeRegressionTest.php`
    (new)
  - `tests/integration/services/WebhookServiceTest.php` (extend)
  - `tests/integration/models/NotificationTemplateUrlSchemeTest.php` (new)
  - `tests/integration/controllers/NotificationTemplateCreatedByTest.php`
    (new)

**Interfaces:**
- **Consumes:** Task 1's `OutboundHttpClient` (via
  `Yii::$app->get('outboundHttp')`).
- **Produces:**
  - `NotificationTemplate::URL_SCHEME_REFUSED = '%s must be an http or
    https URL.'`, applied to every URL key of a channel config;
  - for Mattermost's `server_url`, `'%s must be an https URL.'`
    (constant `HTTPS_REQUIRED`, used in Task 9).

- [ ] **Step 1: Write the failing tests**
  - `testRegressionAChannelRefusesAFileUrlWithoutReadingIt`: a data
    provider over Slack, Teams, Webhook, Telegram (`api_url` if
    configurable, else skip) and PagerDuty with a `file:` URL. The channel
    throws, and the message holds no content of `/etc/hostname`.
    Regression docblock: "an operator's Send Test showed the start of a
    local file".
  - `testRegressionChannelErrorsCarryNoResponseContent`: loopback answers
    `500` with body `secret-hook-id-123`; the error lacks it.
  - `WebhookServiceTest::testARedirectIsAFailure` and
    `testOnlyA2xxStatusIsSuccess`: `302` and `502` both fail, while the
    old code took the "2" in the status line for success.
  - `NotificationTemplateUrlSchemeTest`: saving a slack, teams or webhook
    template with `file:` or `gopher:` gives the field error
    `URL_SCHEME_REFUSED`, and `https:`/`http:` are accepted.
  - `NotificationTemplateCreatedByTest::testRegressionTheWebFormCannotSetCreatedBy`:
    a POST to update with `NotificationTemplate[created_by]=<admin id>`
    keeps the stored value. The same holds for `PUT
    /api/v1/notification-templates/{id}` with `created_by`.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.**
  - Each channel calls `outboundHttp->request()` and keeps its payload
    shape.
  - `WebhookService` uses the client and logs `ErrorText::of($e)`.
  - Mark `created_by` unsafe (`'!created_by'` or remove it from the
    rules), set it only in `beforeSave` on insert.
  - URL keys per channel: slack/teams `webhook_url`, webhook `url`; for
    Task 9 and Task 11, the channel definitions.
- [ ] **Step 4: Run them and verify they pass,** plus
  `tests/unit/services/notification` and `tests/integration` notification
  tests.

### Task 3: Schema

**Files:**
- Create:
  - `migrations/m000091_000000_notification_delivery_and_options.php`
  - `models/NotificationDelivery.php`
  - `models/NotificationThread.php`
- Modify: `models/NotificationTemplate.php`
- Test:
  - `tests/integration/migrations/NotificationDeliveryMigrationTest.php`
  - `tests/integration/models/NotificationDeliveryTest.php`

**Interfaces:**
- **Produces:**
  - **`notification_template` changes:**
    - `events` widened to `varchar(1024)`;
    - `level varchar(16) NOT NULL DEFAULT 'all'`;
    - `workflow_scope varchar(16) NOT NULL DEFAULT 'all'`;
    - `delivers_as int unsigned NOT NULL`, FK user RESTRICT, backfilled
      from `created_by`;
    - `channel_state text NULL`: server-managed JSON, never in rules.
  - **`notification_template_workflow_template`:**
    `notification_template_id` FK CASCADE, `workflow_template_id` FK
    CASCADE, primary key on both.
  - **`notification_delivery`**, with indexes `(status, next_attempt_at)`,
    `(notification_template_id, created_at)`, `(workflow_job_id, id)`:
    - `id`;
    - `notification_template_id` FK CASCADE;
    - `event varchar(64)`;
    - `kind varchar(16)`;
    - `workflow_job_id` NULL FK CASCADE, `job_id` NULL,
      `approval_request_id` NULL;
    - `variables text`;
    - `status varchar(16)`;
    - `attempts int NOT NULL DEFAULT 0`;
    - `next_attempt_at int`, `claimed_at int NULL`;
    - `last_error varchar(500) NULL`;
    - `remote_id varchar(64) NULL`;
    - `created_at`, `updated_at`, `finished_at NULL`.
  - **`notification_thread`:**
    - `id`;
    - `notification_template_id` FK CASCADE, `workflow_job_id` FK CASCADE,
      unique on both;
    - `root_post_id varchar(64)`, `channel_id varchar(64)`,
      `server varchar(255)`;
    - `created_at`, `updated_at`.
  - **Model constants:**
    - `NotificationDelivery::STATUS_PENDING/SENDING/DELIVERED/FAILED/SKIPPED/SUPERSEDED`
      (lower-case words);
    - `KIND_MESSAGE='message'`, `KIND_STATUS='status'`,
      `KIND_REPLY='reply'`;
    - `NotificationTemplate::LEVEL_ALL='all'`,
      `LEVEL_ESSENTIAL='essential'`, `WORKFLOW_SCOPE_ALL='all'`,
      `WORKFLOW_SCOPE_SELECTED='selected'`;
    - relations `NotificationTemplate::getWorkflowTemplates()` and
      `getDeliveries()`.

- [ ] **Step 1: Write the failing tests.**
  - The migration test asserts:
    - the columns, the FKs with their actions and the indexes;
    - the backfill `delivers_as = created_by`;
    - that `safeDown` reverts it.
  - The model tests assert the defaults and the relations, and that
    `channel_state` and `delivers_as` are not mass-assignable.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement the migration and the models.**
- [ ] **Step 4: Run them and verify they pass.** Run them on a fresh test
  database too, following the PHPUnit suite's migration end-to-end check.

### Task 4: Template identity, filter and level

**Files:**
- Create: `services/notification/NotificationIdentity.php`
- Modify: `models/NotificationTemplate.php`, `services/AuditService.php`
  if a new action constant is needed
- Test: `tests/integration/services/notification/NotificationIdentityTest.php`

**Interfaces:**
- **Consumes:** Task 3's columns.
- **Produces:**
  - `NotificationIdentity::changesIdentity(NotificationTemplate $t):
    bool`: true when the channel, the destination config keys (the
    channel's URL, token, channel and chat keys), `events`,
    `workflow_scope`, the filter or `level` changed;
  - `NotificationIdentity::apply(NotificationTemplate $t, int $userId):
    void`: sets `delivers_as` and records the audit entry
    `notification-template.updated` with `delivers_as: {from, to}`;
  - `NotificationTemplate::isDeliveringAsInactive(): bool`;
  - the pivot is written through `NotificationTemplate::setWorkflowTemplateIds(array<int> $ids, int $editorId)`:
    only IDs the editor may see are added or removed, and the others are
    kept.

- [ ] **Step 1: Write the failing tests.**
  - `testEditingTheDestinationMakesTheEditorTheIdentity`.
  - `testRenamingKeepsTheIdentity`.
  - `testRegressionAnEditorCannotInheritTheCreatorsIdentity`: an operator
    edits an admin's template destination, and `delivers_as` becomes the
    operator.
  - `testFilterKeepsWorkflowsTheEditorCannotSee`.
  - `testASelectedFilterWhoseWorkflowsWereDeletedMatchesNothing`.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement,** following `services/ScheduleRunAs.php`.
- [ ] **Step 4: Run them and verify they pass.**

### Task 5: Notification queue and worker service

**Files:**
- Modify:
  - `config/console.php`, `config/web.php`, `config/test.php` (component
    `notificationQueue`: redis, channel `ansilume-notifications`, `ttr`
    120, serializer `AllowlistQueueSerializer`)
  - `components/AllowlistQueueSerializer.php` (allow
    `app\jobs\DeliverNotificationJob`)
  - `commands/WorkerController.php` (option `--queue`, default `queue`)
  - `docker-compose.yml`, `docker-compose.prebuilt.yml`,
    `deploy/roles/ansilume/templates/docker-compose.yaml` (service
    `notification-worker`, container `ansilume_notification_worker`,
    command `php yii worker/start --queue=notificationQueue`, the
    queue-worker's health check and user rules, heartbeat key prefix
    `ansilume:notification-worker:`)
  - `bin/diagnose`, `bin/quickstart` (know the service)
- Test:
  - `tests/unit/docker/IdleDatabaseConnectionTest.php` (PROCESSES entry)
  - `tests/unit/components/AllowlistQueueSerializerTest.php`
  - `tests/integration/commands/WorkerJobDbConnectionTest.php` (provider:
    a `DeliverNotificationJob` case)
  - compose tests that list services (HealthcheckUserTest,
    ComposeIsolationTest, ExceptionIgnoreArgsTest composeProvider,
    DeployRoleComposeTest)

**Interfaces:**
- **Produces:**
  - `Yii::$app->get('notificationQueue')`;
  - `WorkerController::actionStart(string $queue = 'queue')`;
  - the heartbeat of the notification worker does not count as a sync
    worker for the project page.

- [ ] **Step 1: Write the failing tests:** the compose and deploy tests
  expect the new service with health check, user, ini and the database
  connection rules; the serializer allows the class; the worker uses the
  component.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and verify they pass:** `tests/unit/docker`,
  `tests/unit/bin`, `WorkerJobDbConnectionTest`.

### Task 6: Delivery service, job and sweeper

**Files:**
- Create:
  - `jobs/DeliverNotificationJob.php`
  - `services/notification/NotificationDeliveryService.php`
  - `services/notification/DeliveryOutcome.php`
- Modify: `services/MaintenanceService.php` (task `notification-sweep`)
- Test:
  - `tests/integration/services/notification/NotificationDeliveryServiceTest.php`
  - `tests/integration/services/MaintenanceServiceTest.php`

**Interfaces:**
- **Consumes:** Task 1, Task 3, Task 5; the channel map of
  `NotificationDispatcher`.
- **Produces:**
  - `DeliverNotificationJob` with a single property, `public int
    $deliveryId`; its `execute()` calls `deliver()`;
  - `NotificationDeliveryService::deliver(int $deliveryId): void`. It:
    - claims the row with a conditional UPDATE;
    - waits for earlier rows of its (template, workflow_job_id) by
      re-pushing with 5 s delay;
    - re-checks visibility (Task 7's `NotificationVisibility`);
    - sends;
    - classifies the outcome: retry with backoff, permanent, superseded,
      skipped;
    - writes one audit entry per final outcome.
  - Properties `array<int> $backoffSeconds`, `int $maxAttempts = 8`.
  - `NotificationDeliveryService::sweep(int $now): array{repushed: int,
    reset: int, purged: int}`.
  - `NotificationDeliveryService::enqueuePush(int $deliveryId): void`:
    never throws, logs `DbErrorSummary`.

- [ ] **Step 1: Write the failing tests.**
  - `testADeliveryIsClaimedOnce`: two calls, one send.
  - `testAThreadRowWaitsForEarlierRowsOfItsRun`.
  - `testTransientErrorsRetryWithTheBackoff`: 0/timeout, 429 with
    `Retry-After`, 5xx.
  - `testPermanentErrorsFailAtOnce`: 400, 401, 403, 404, 413.
  - `testAfterMaxAttemptsTheDeliveryFails`.
  - `testAChannelErrorNeverEscapesTheJob`: `QueueLogBehavior` logs
    nothing raw.
  - `testTheSweeperRepushesLostAndStaleDeliveries`: Review focus 2.
  - `testFinishedDeliveriesArePurgedAfter30Days`.
  - `testOneAuditEntryPerFinalOutcome`.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and verify they pass.**

### Task 7: Producer and visibility

**Files:**
- Create:
  - `services/notification/NotificationVisibility.php`
  - `services/notification/NotificationOutbox.php`
  - `components/AppLinks.php`
- Modify:
  - `services/NotificationDispatcher.php` (`dispatch()` writes the outbox
    and pushes; `sendSingle()` stays synchronous; throttle
    `webhook.invalid_token`)
  - `services/JobCompletionService.php` (a notification failure never
    skips the workflow advance)
- Test:
  - `tests/integration/services/notification/NotificationVisibilityTest.php`
  - `tests/integration/services/NotificationDispatcherTest.php` (update)
  - `tests/unit/components/AppLinksTest.php`
  - the doubles `RecordingNotificationDispatcher` and
    `FailingNotificationDispatcher` keep `dispatch(string, array)`

**Interfaces:**
- **Consumes:** Tasks 3, 4, 6.
- **Produces:**
  - `NotificationVisibility::allows(NotificationTemplate $t, string
    $event, array{workflow_job_id?: int, job_id?: int,
    approval_request_id?: int, project_id?: int, job_template_id?: int}
    $refs): bool`. The rules follow the spec's visibility table as
    amended by its answer 3. Gate first on `Viewer::of($id)->may()`, and
    deny an inactive identity.
  - `NotificationVisibility::visibleWorkflowTemplateIds(int $viewerId,
    array<int> $ids): array<int>`.
  - Producers add `refs` to the payload, e.g. `$payload['refs'] =
    ['workflow_job_id' => 12]`.
  - `AppLinks::workflowRun(int $id): string`, `job(int $id): string`,
    `approval(int $id): string`, all from `params.appBaseUrl` and
    console-safe.
  - `NotificationOutbox::enqueue(NotificationTemplate $t, string $event,
    array<string, string> $variables, array $refs, string $kind):
    NotificationDelivery`.

- [ ] **Step 1: Write the failing tests.**
  - `testATemplateDeliversOnlyWhatItsIdentityMaySee` (Review focus 5):
    teams A and B, an operator's template, an admin's template edited by
    the operator.
  - `testAnInactiveIdentityDeliversNothing`.
  - `testRunnerEventsNeedRunnerGroupView`.
  - `testInvalidTokenEventsAreThrottledPerTemplate`: two within 300 s
    give one row.
  - `testDispatchNeverThrowsWhenTheQueueIsDown`.
  - `testRegressionACompletionAdvancesItsWorkflowWhenNotificationsFail`.
  - `testLinksAreAbsoluteFromAppBaseUrlInTheConsole`.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.** Update every test that asserted synchronous
  sending: it now asserts outbox rows, or runs `deliver()`.
- [ ] **Step 4: Run them and verify they pass:** all notification tests,
  `tests/integration/services`.

### Task 8: Workflow events and payloads

**Files:**
- Modify:
  - `services/WorkflowRunEvents.php`
  - `services/WorkflowExecutionService.php` (`executeStep()` end,
    `endStep`, `resume()`, `failStep`)
  - `services/WorkflowCancellationService.php` (`canceled` after the
    steps)
  - `services/ApprovalEffects.php` (workflow context, a status refresh on
    settle)
  - `services/notification/JobPayloadBuilder.php` (run id for step jobs)
  - `models/NotificationTemplate.php`: constants
    `EVENT_WORKFLOW_STEP_STARTED='workflow.step_started'`,
    `EVENT_WORKFLOW_STEP_SUCCEEDED='workflow.step_succeeded'`,
    `EVENT_WORKFLOW_WAITING='workflow.waiting'`; also `eventGroups`,
    `eventSeverity` (info, info, warning), the presets and
    `essentialWorkflowEvents()`
  - `services/notification/PagerDutyChannel.php` (the two new info
    events never trigger)
  - `components/NotificationTestVariables.php`
- Test:
  - `tests/integration/services/WorkflowStepEventsTest.php` (new)
  - existing tests that assert exact event lists
    (`WorkflowCancellationServiceTest`, `JobClaimGateTest`): update their
    expectations

**Interfaces:**
- **Consumes:** Task 7's `refs` and `AppLinks`; `WorkflowRunStates`.
- **Produces:**
  - `WorkflowRunEvents::stepStarted(WorkflowJob $run, WorkflowJobStep
    $execution): void`;
  - `stepSucceeded(...)`;
  - `waiting(..., string $waitingFor, ?int $approvalRequestId)`;
  - `statusChanged(WorkflowJob $run): void` (status-only);
  - payload keys:
    - `workflow.url`;
    - `step.execution_id`, `step.workflow_step_id`, `step.position`,
      `step.name`, `step.type`, `step.waiting_for`;
    - `approval.url`;
    - `workflow.steps_done`, `workflow.steps_total`;
    - `workflow.reason`.

- [ ] **Step 1: Write the failing tests.**
  - `testAJobStepFiresStartedThenSucceeded`.
  - `testAnApprovalStepFiresStartedThenWaitingForApproval`.
  - `testAPauseStepFiresWaitingForResumeAndSucceededOnResume`.
  - `testAStepThatCouldNotStartFiresStepFailed`.
  - `testCanceledComesAfterTheStepsEnded`.
  - `testALoopPassFiresStepEventsOncePerExecution` (Review focus 1).
  - `testARefusedTransitionFiresNothing`.
  - `testSettlingAWorkflowApprovalRefreshesTheStatus`.
  - `testTheNewInfoEventsNeverTriggerPagerDuty`.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement the hooks after each conditional transition
  returned true.** Derive `waiting_for` with `WorkflowRunStates`'
  `runningState` code, not a copy.
- [ ] **Step 4: Run them and verify they pass,** plus
  `tests/integration/services` for the workflow and approval suites.

### Task 9: Mattermost channel

**Files:**
- Create:
  - `services/notification/MattermostChannel.php`
  - `services/notification/MattermostApi.php`
  - `services/notification/MattermostText.php`
  - `services/notification/MattermostStatusPost.php`
  - `services/notification/ThreadedChannelInterface.php`
- Modify:
  - `models/NotificationTemplate.php`: `CHANNEL_MATTERMOST='mattermost'`;
    config keys `server_url`, `bot_token`, `channel_id`, `team`,
    `channel`, `mention`; secret `bot_token`; URL key `server_url`
    (https only)
  - `components/NotificationConfigSecrets.php`: `bot_token` bound to
    `server_url`
  - `services/NotificationDispatcher.php`: register the channel; Send
    Test checks
- Test:
  - `tests/integration/services/notification/MattermostChannelTest.php`
    (fake transport)
  - `tests/unit/services/notification/MattermostTextTest.php`
  - `tests/unit/components/NotificationConfigSecretsTest.php` (binding)
  - `tests/unit/SensitiveParametersTest.php`

**Interfaces:**
- **Consumes:**
  - Task 1's client (injected as `MattermostApi::__construct(OutboundHttpClient $http)`);
  - Task 3's `NotificationThread`;
  - Task 8's payloads;
  - `WorkflowRunStates::forRun($run, Viewer::of($identity))`.
- **Produces:**
  - `ThreadedChannelInterface extends ChannelInterface`:
    - `syncStatus(NotificationTemplate $t, ?NotificationThread $thread,
      WorkflowJob $run, int $deliveryId): NotificationThread`;
    - `reply(NotificationTemplate $t, NotificationThread $thread, string
      $subject, string $body, array $variables, bool $attention, int
      $deliveryId): string`;
  - `MattermostApi`:
    - `me()`;
    - `channel(string $id)`, `channelByName(string $team, string $name)`,
      `membership(string $channelId)`;
    - `createPost(string $channelId, string $message, array $props,
      ?string $rootId, string $pendingPostId): string`;
    - `patchPost(string $postId, string $message, array $props): void`;
    - `MattermostApiException` carries the error `id`, the status and the
      request ID;
  - `MattermostText::escape(string $userText): string`,
    `truncate(string $message, string $runUrl): string`.

- [ ] **Step 1: Write the failing tests.**
  - `testTheFirstEventCreatesTheStatusPostAndLaterOnesEditIt`.
  - `testRepliesFollowTheLevel`.
  - `testAttentionRepliesStartWithTheMention`.
  - `testADeletedStatusPostIsPostedAgain`: patch 403, retried once, then
    a new post.
  - `testAReplyToADeletedRootCreatesANewRoot`.
  - `testUnauthorizedNamesTheCauses` (401).
  - `testRateLimitWaitsRetryAfter`.
  - `testSendTestRefusesAPersonalToken`.
  - `testSendTestRequiresChannelMembership`.
  - `testUserTextCannotMentionOrBreakTheLayout` (Review focus 4).
  - `testRegressionAStoredBotTokenNeverGoesToAChangedServer` (Review
    focus 3).
  - `testSavingNeedsNoServer`.
  - `testHttpServerUrlIsRefused`.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.** The status post is rendered from the run's
  current state for the identity. Older pending status rows of the same
  thread become `superseded`.
- [ ] **Step 4: Run them and verify they pass.**

### Task 10: Signed webhook payload

**Files:**
- Modify:
  - `services/notification/WebhookChannel.php`: `signing_secret` (secret,
    bound to `url`); `payload` (`template` | `event`)
  - `components/NotificationConfigSecrets.php`
  - `models/NotificationTemplate.php`
- Create: `services/notification/NotificationEventPayload.php`
- Test: `tests/integration/services/notification/WebhookChannelSigningTest.php`

**Interfaces:**
- **Produces:**
  - `NotificationEventPayload::build(string $event, array $variables,
    array $refs): array`, shaped like the API resources;
  - headers `X-Ansilume-Signature: t=<unix>,v1=<hex hmac-sha256 of
    "<t>.<body>">`, `X-Ansilume-Delivery: <delivery id>`.

- [ ] **Step 1: Write the failing tests:** the signature verifies with the
  documented recipe; the delivery ID is stable across a retry; `template`
  stays the default for existing rows; the secret binding.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and verify they pass.**

### Task 11: Channel registry by configuration

**Files:**
- Create: `services/notification/ChannelDefinition.php`
- Modify:
  - `services/NotificationDispatcher.php` (public `array $channels`
    config; `definitions(): array<string, ChannelDefinition>`)
  - `models/NotificationTemplate.php` and
    `components/NotificationConfigSecrets.php` (read definitions instead
    of constants)
  - `config/services.php`
- Test: `tests/integration/services/notification/ChannelRegistryTest.php`

**Interfaces:**
- **Produces:**
  - `ChannelDefinition`:
    - readonly `string $name`, `string $label`, `string $class`;
    - `list<string> $requiredKeys`, `list<string> $secretKeys`,
      `array<string, list<string>> $secretMaps`;
    - `array<string, string> $boundTo` (secret key → key it is bound to);
    - `array<string, list<string>> $urlSchemes`;
  - built-in channels are defined the same way; a configured class must
    implement `ChannelInterface`.

- [ ] **Step 1: Write the failing tests:** a configured test channel can
  be saved, validated, has its secret redacted and bound, and receives a
  delivery; an unknown class is refused at init.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and verify they pass,** plus every notification
  test.

### Task 12: REST API and OpenAPI

**Files:**
- Modify:
  - `controllers/api/v1/NotificationTemplatesController.php`
  - `config/web.php` (route
    `GET api/v1/notification-templates/<id:\d+>/deliveries`)
  - `web/openapi.yaml`
- Test:
  - `tests/integration/controllers/api/v1/NotificationTemplatesApiTest.php`
    (extend)
  - `NotificationTemplateDeliveriesApiTest.php` (new)
  - `SubmittedNumbersApiTest::OPERATIONS` (`workflow_template_ids`)
  - `OpenApiSecretsContractTest`

**Interfaces:**
- **Produces:**
  - fields:
    - `workflow_scope`, `workflow_template_ids` (array; the viewer's
      visible part plus `workflow_templates_hidden: int`);
    - `level`;
    - `delivers_as`, `delivers_as_inactive` (read-only);
    - `events` (array or comma string on write);
    - the Mattermost and webhook config with `$encrypted$`;
  - deliveries: paginated through `BaseApiController::modelsOfPage()`,
    filtered to the viewer, with `hidden` counts.

- [ ] **Step 1: Write the failing tests:**
  - codes and shapes;
  - floats and lists refused for IDs;
  - a viewer of team B does not see team A's deliveries;
  - `PUT` keeps the IDs the editor cannot see.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and verify they pass,** plus the OpenAPI lint in
  `bin/tests-lint.sh`'s checker (run the PHP checker directly).

### Task 13: Web UI and E2E

**Files:**
- Modify:
  - `views/notification-template/form.php`
  - `views/notification-template/view.php`
  - `controllers/NotificationTemplateController.php`
- Test:
  - `tests/e2e/tests/notification-templates/mattermost.spec.ts` (new)
  - `deliveries.spec.ts` (new)
  - `rbac.spec.ts` (extend)
  - `commands/E2eController.php` (seed one e2e- Mattermost template and
    deliveries)

**Interfaces:**
- **Consumes:** Tasks 3, 4, 9, 10, 12.

- [ ] **Step 1: Write the failing specs:**
  - admin creates a Mattermost template (https URL, token, team and
    channel, mention); validation errors (http URL, `@all` mention);
  - the filter switch with a multi-select of visible workflows; the level;
  - the view page shows "Delivers as", a warning when inactive, and the
    deliveries table;
  - the operator may create;
  - the viewer has no create or edit.
- [ ] **Step 2: Run them and verify they fail:**
  `docker compose exec -T app php yii e2e/seed`, then `docker compose
  --profile e2e run --build --rm playwright npx playwright test
  tests/notification-templates`, then
  `docker compose exec -T app php yii e2e/teardown`. Both data commands
  run as root on purpose; see `YiiExecUserTest`'s AS_ROOT.
- [ ] **Step 3: Implement the form, the view and the controller.**
- [ ] **Step 4: Run them and verify they pass,** with seed and teardown
  as above.

### Task 14: Docs, diagnose, contract tests

**Files:**
- Create:
  - `docs/notifications.md`
  - `tests/unit/docs/WorkflowNotificationsDocsContractTest.php`
- Modify:
  - `docs/updating.md`: a new release section "After updating from 2.14.0
    or older" (extend the Redis one)
  - `docs/troubleshooting.md`
  - `README.md`
  - `CLAUDE.md`: domain language, the new process, the security rule on
    outbound HTTP
  - `bin/diagnose`: inactive identities, `APP_URL` default
  - `tests/unit/docs/ErrorReportsDocsContractTest.php`
  - `WorkflowStepsUpdateDocsContractTest.php` (limit to that release's
    channels)
- Test: the docs contract tests.

- [ ] **Step 1: Write the failing contract tests.** The docs name:
  - the events, the variables and Mattermost's setup steps;
  - the retry budget, `OUTBOUND_CA_BUNDLE`, the worker service;
  - the signature recipe;
  - the security fix;
  - the identity rules.
- [ ] **Step 2: Run them and verify they fail.**
- [ ] **Step 3: Write the docs and update `bin/diagnose`.**
- [ ] **Step 4: Run them and verify they pass:** `tests/unit/docs`,
  `tests/integration/docs`, `tests/unit/bin`.

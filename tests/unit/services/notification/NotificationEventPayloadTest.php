<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\NotificationEventPayload;
use app\tests\integration\controllers\api\v1\OpenApiSchemaAssertions;
use PHPUnit\Framework\TestCase;

/**
 * The event payload of the webhook channel ({"payload": "event"}): the
 * event, its ID, time and severity, and the objects of its group shaped
 * like the REST API's resources, with links, built only from what the
 * delivery keeps of the event (its variables, as the template's identity
 * may see them, and its refs); and web/openapi.yaml documents exactly
 * that (NotificationEventPayload and the schemas of its objects).
 */
class NotificationEventPayloadTest extends TestCase
{
    use OpenApiSchemaAssertions;

    private const BASE = 'https://ansilume.example.com';

    private const EVENT_ID = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';

    private const OCCURRED_AT = 1762250000;

    /** The schema of each object of a payload. */
    private const SCHEMAS = [
        'workflow' => 'NotificationEventWorkflow',
        'step' => 'NotificationEventStep',
        'job' => 'NotificationEventJob',
        'approval' => 'NotificationEventApproval',
        'project' => 'NotificationEventProject',
        'schedule' => 'NotificationEventSchedule',
        'runner' => 'NotificationEventRunner',
        'trigger' => 'NotificationEventTrigger',
    ];

    /**
     * A job's event outside a run: the job, with its template's name and its
     * launcher, and its project, each with its page.
     */
    public function testAJobEventCarriesTheJobAndItsProject(): void
    {
        $payload = NotificationEventPayload::build('job.failed', self::jobVariables(), ['job_id' => 42]);

        $this->assertSame([
            'event' => 'job.failed',
            'event_id' => self::EVENT_ID,
            'occurred_at' => self::OCCURRED_AT,
            'severity' => 'critical',
            'job' => self::job(),
            'project' => [
                'id' => 3,
                'name' => 'Infra',
                'status' => null,
                'last_sync_error' => null,
                'url' => self::BASE . '/project/view?id=3',
            ],
        ], $payload);
        $this->assertMatchesTheSpec($payload);
    }

    /**
     * The job of a workflow step: the run and the step too, for an identity
     * that may see the run's workflow.
     */
    public function testAJobEventOfARunCarriesTheRunAndTheStep(): void
    {
        $variables = self::jobVariables() + self::runVariables();

        $payload = NotificationEventPayload::build('job.failed', $variables, ['workflow_job_id' => 12, 'job_id' => 42]);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', 'job', 'project', 'workflow', 'step'], array_keys($payload));
        $this->assertSame(self::workflow(), $payload['workflow']);
        $this->assertSame(self::step(['approval_request_id' => null]), $payload['step']);
        $this->assertMatchesTheSpec($payload);
    }

    /**
     * regression guard: an identity that may see the job but not the run's
     * workflow gets the job's event without the run's context, which the
     * dispatcher leaves out of its variables; its refs still name the run,
     * and the payload must not show it either.
     */
    public function testWithoutTheRunsContextThePayloadNamesNoRun(): void
    {
        $payload = NotificationEventPayload::build('job.failed', self::jobVariables(), ['workflow_job_id' => 12, 'job_id' => 42]);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', 'job', 'project'], array_keys($payload));
        $this->assertStringNotContainsString('workflow', (string)json_encode($payload));
    }

    /**
     * A step that waits for an approval: the run, the step with its request,
     * and the request with its page; a workflow event names no job of its
     * own, its step does.
     */
    public function testAWaitingStepCarriesItsApprovalRequest(): void
    {
        $variables = self::runVariables() + [
            'event' => 'workflow.waiting',
            'severity' => 'warning',
            'event_id' => self::EVENT_ID,
            'occurred_at' => (string)self::OCCURRED_AT,
            'app.url' => self::BASE,
            'approval.url' => self::BASE . '/approval/view?id=9',
        ];

        $payload = NotificationEventPayload::build('workflow.waiting', $variables, ['workflow_job_id' => 12, 'job_id' => 42, 'approval_request_id' => 9]);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', 'workflow', 'step', 'approval'], array_keys($payload));
        $this->assertSame('warning', $payload['severity']);
        $this->assertSame(self::workflow(), $payload['workflow']);
        $this->assertSame(self::step(['approval_request_id' => 9]), $payload['step']);
        $this->assertSame([
            'id' => 9,
            'status' => null,
            'approval_rule_id' => null,
            'approval_rule_name' => null,
            'required_by' => null,
            'job_id' => 42,
            'url' => self::BASE . '/approval/view?id=9',
        ], $payload['approval']);
        $this->assertMatchesTheSpec($payload);
    }

    /**
     * An event of the whole run, whose newest step waits for nothing, and a
     * step that was removed from the workflow since: no position.
     */
    public function testARunEventCarriesItsNewestStep(): void
    {
        $variables = array_merge(self::runVariables(), [
            'workflow.status' => 'succeeded',
            'workflow.reason' => '',
            'step.workflow_step_id' => '',
            'step.position' => '',
            'step.waiting_for' => '',
            'event' => 'workflow.succeeded',
            'severity' => 'info',
        ]);

        $payload = NotificationEventPayload::build('workflow.succeeded', $variables, ['workflow_job_id' => 12]);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', 'workflow', 'step'], array_keys($payload));
        $this->assertSame(['succeeded', null], [$payload['workflow']['status'], $payload['workflow']['reason']]);
        $this->assertSame(
            ['workflow_step_id' => null, 'position' => null, 'approval_request_id' => null, 'waiting_for' => null],
            array_intersect_key($payload['step'], array_flip(['workflow_step_id', 'position', 'waiting_for', 'approval_request_id']))
        );
        $this->assertNull($payload['event_id'], 'a delivery written before the variable existed has no event ID');
        $this->assertNull($payload['occurred_at']);
        $this->assertMatchesTheSpec($payload);
    }

    /**
     * An approval event of a job its inventory's rule gates: the request
     * with its rule and what required it, and its job with the inventory
     * it was launched with; the job's page without a link of its own.
     */
    public function testAnApprovalEventCarriesTheRequestAndItsJob(): void
    {
        $variables = [
            'timestamp' => '2026-11-04 10:00:00 UTC',
            'app.url' => self::BASE,
            'approval.id' => '9',
            'approval.status' => 'pending',
            'approval.rule_id' => '4',
            'approval.rule_name' => 'Production Gate',
            'approval.required_by' => 'inventory',
            'approval.url' => self::BASE . '/approval/view?id=9',
            'job.id' => '42',
            'job.status' => 'pending_approval',
            'job.template_id' => '7',
            'job.inventory_id' => '5',
            'job.inventory_name' => 'Production hosts',
            'event' => 'approval.requested',
            'severity' => 'warning',
            'event_id' => self::EVENT_ID,
            'occurred_at' => (string)self::OCCURRED_AT,
        ];

        $payload = NotificationEventPayload::build('approval.requested', $variables, ['job_id' => 42, 'approval_request_id' => 9]);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', 'approval', 'job'], array_keys($payload));
        $this->assertSame([
            'id' => 9,
            'status' => 'pending',
            'approval_rule_id' => 4,
            'approval_rule_name' => 'Production Gate',
            'required_by' => 'inventory',
            'job_id' => 42,
            'url' => self::BASE . '/approval/view?id=9',
        ], $payload['approval']);
        $this->assertSame([
            'id' => 42,
            'status' => 'pending_approval',
            'job_template_id' => 7,
            'template_name' => null,
            'inventory_id' => 5,
            'inventory_name' => 'Production hosts',
            'launched_by' => null,
            'exit_code' => null,
            'url' => self::BASE . '/job/view?id=42',
        ], $payload['job']);
        $this->assertMatchesTheSpec($payload);
    }

    /**
     * The events of projects, schedules, runners and triggers carry their
     * object, a schedule's job template from the refs.
     *
     * @dataProvider objectEvents
     * @param array<string, string> $variables
     * @param array<string, int> $refs
     * @param array<string, mixed> $object
     */
    public function testEveryOtherGroupCarriesItsObject(string $event, array $variables, array $refs, string $name, array $object): void
    {
        $payload = NotificationEventPayload::build($event, $variables + ['app.url' => self::BASE, 'event_id' => self::EVENT_ID], $refs);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', $name], array_keys($payload));
        $this->assertSame(NotificationTemplate::eventSeverity($event), $payload['severity'], 'without the variable, the severity of the event');
        $this->assertSame($object, $payload[$name]);
        $this->assertMatchesTheSpec($payload);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, int>, 3: string, 4: array<string, mixed>}>
     */
    public static function objectEvents(): array
    {
        return [
            'a failed sync' => [
                'project.sync_failed',
                ['project.id' => '3', 'project.name' => 'Infra', 'project.status' => 'error', 'project.error' => 'git fetch failed'],
                ['project_id' => 3],
                'project',
                ['id' => 3, 'name' => 'Infra', 'status' => 'error', 'last_sync_error' => 'git fetch failed', 'url' => self::BASE . '/project/view?id=3'],
            ],
            'a schedule that did not launch' => [
                'schedule.failed_to_launch',
                ['schedule.id' => '6', 'schedule.name' => 'Nightly', 'schedule.cron' => '0 2 * * *', 'schedule.error' => 'Template not found'],
                ['job_template_id' => 7],
                'schedule',
                ['id' => 6, 'name' => 'Nightly', 'cron_expression' => '0 2 * * *', 'job_template_id' => 7, 'error' => 'Template not found', 'url' => self::BASE . '/schedule/view?id=6'],
            ],
            'a runner offline' => [
                'runner.offline',
                ['runner.id' => '2', 'runner.name' => 'runner-2', 'runner.last_seen_at' => '1762249000'],
                [],
                'runner',
                ['id' => 2, 'name' => 'runner-2', 'last_seen_at' => 1762249000],
            ],
            'a runner never seen' => [
                'runner.recovered',
                ['runner.id' => '2', 'runner.name' => 'runner-2', 'runner.last_seen_at' => ''],
                [],
                'runner',
                ['id' => 2, 'name' => 'runner-2', 'last_seen_at' => null],
            ],
            'an unknown trigger token' => [
                'webhook.invalid_token',
                ['trigger.token_prefix' => 'abc123', 'trigger.kind' => 'workflow', 'trigger.ip' => '192.0.2.10', 'trigger.user_agent' => 'curl/8.0', 'trigger.note' => 'Further calls are in the audit log.'],
                [],
                'trigger',
                ['kind' => 'workflow', 'token_prefix' => 'abc123', 'ip' => '192.0.2.10', 'user_agent' => 'curl/8.0', 'note' => 'Further calls are in the audit log.'],
            ],
            'a job template trigger' => [
                'webhook.invalid_token',
                ['trigger.token_prefix' => 'abc123', 'trigger.ip' => '', 'trigger.user_agent' => '', 'trigger.note' => ''],
                [],
                'trigger',
                ['kind' => null, 'token_prefix' => 'abc123', 'ip' => null, 'user_agent' => null, 'note' => null],
            ],
        ];
    }

    /**
     * The objects of an event are those of its group only: a test send
     * carries the sample variables of every group, and a template's
     * payload must not mix them.
     */
    public function testAnEventCarriesOnlyTheObjectsOfItsGroup(): void
    {
        $everything = self::jobVariables() + self::runVariables() + [
            'schedule.id' => '6',
            'runner.id' => '2',
            'trigger.token_prefix' => 'abc123',
            'approval.id' => '9',
        ];

        $this->assertSame(['job', 'project', 'workflow', 'step'], self::objectsOf(NotificationEventPayload::build('job.succeeded', $everything, [])));
        $this->assertSame(['runner'], self::objectsOf(NotificationEventPayload::build('runner.offline', $everything, [])));
        $this->assertSame([], self::objectsOf(NotificationEventPayload::build('test', $everything, [])), 'an event of no group carries no object');
    }

    /**
     * An object whose ID the variables do not hold is left out, rather than
     * sent without an ID: such as an event of a delivery written before
     * its producer named the object.
     *
     * @dataProvider eventsWithoutTheirObject
     */
    public function testAnObjectWithoutItsIdIsLeftOut(string $event): void
    {
        $payload = NotificationEventPayload::build($event, ['app.url' => self::BASE, 'event' => $event, 'job.status' => 'failed', 'schedule.name' => 'Nightly'], []);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity'], array_keys($payload));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function eventsWithoutTheirObject(): array
    {
        return [
            'a job' => ['job.failed'],
            'an approval' => ['approval.rejected'],
            'a run' => ['workflow.failed'],
            'a schedule' => ['schedule.failed_to_launch'],
            'a runner' => ['runner.offline'],
            'a project' => ['project.sync_succeeded'],
            'a trigger' => ['webhook.invalid_token'],
        ];
    }

    /**
     * A value that is no whole number is no ID: null, never a number the
     * text does not say.
     */
    public function testAnIdThatIsNoWholeNumberIsNull(): void
    {
        $variables = array_merge(self::jobVariables(), ['job.template_id' => '7.5', 'job.exit_code' => '-1', 'project.id' => 'abc']);

        $payload = NotificationEventPayload::build('job.failed', $variables, []);

        $this->assertSame([null, -1], [$payload['job']['job_template_id'], $payload['job']['exit_code']]);
        $this->assertArrayNotHasKey('project', $payload, 'an object whose ID is no whole number is left out');
    }

    /**
     * The example of docs/notifications.md is what the builder makes of a
     * step that waits for an approval.
     */
    public function testTheDocsExampleIsWhatTheBuilderMakes(): void
    {
        $doc = (string)file_get_contents(dirname(__DIR__, 4) . '/docs/notifications.md');
        $section = substr($doc, (int)strpos($doc, "\n### The event payload\n"));
        $this->assertSame(1, preg_match('/^```json\n(.*?)^```$/ms', $section, $match), 'the section has a JSON example');
        $example = json_decode($match[1], true);
        $this->assertIsArray($example);

        $variables = self::runVariables() + [
            'event' => 'workflow.waiting',
            'severity' => 'warning',
            'event_id' => (string)$example['event_id'],
            'occurred_at' => (string)self::OCCURRED_AT,
            'app.url' => self::BASE,
            'approval.url' => self::BASE . '/approval/view?id=9',
        ];

        $this->assertSame($example, NotificationEventPayload::build('workflow.waiting', $variables, ['workflow_job_id' => 12, 'job_id' => 42, 'approval_request_id' => 9]));
    }

    /**
     * The payload's JSON as the channel sends it: slashes and other
     * characters as they are.
     */
    public function testTheJsonKeepsSlashesAndCharactersAsTheyAre(): void
    {
        $payload = NotificationEventPayload::build('job.failed', array_merge(self::jobVariables(), ['template.name' => 'Déploy']), []);

        $json = NotificationEventPayload::json($payload);

        $this->assertStringContainsString('"url":"https://ansilume.example.com/job/view?id=42"', $json);
        $this->assertStringContainsString('"template_name":"Déploy"', $json);
        $this->assertSame($payload, json_decode($json, true));
    }

    /**
     * $payload has exactly the documented properties of
     * NotificationEventPayload, and each of its objects those of its schema.
     *
     * @param array<string, mixed> $payload
     */
    private function assertMatchesTheSpec(array $payload): void
    {
        $absent = array_values(array_diff(array_keys(self::SCHEMAS), array_keys($payload)));
        $this->assertMatchesSchema('NotificationEventPayload', $payload, $absent);
        foreach (self::SCHEMAS as $name => $schema) {
            if (isset($payload[$name])) {
                $this->assertIsArray($payload[$name]);
                $this->assertMatchesSchema($schema, $payload[$name]);
                $this->assertSame(
                    '#/components/schemas/' . $schema,
                    $this->schema('NotificationEventPayload')['properties'][$name]['$ref'] ?? null,
                    "NotificationEventPayload.{$name} refers to {$schema}."
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function objectsOf(array $payload): array
    {
        return array_values(array_diff(array_keys($payload), ['event', 'event_id', 'occurred_at', 'severity']));
    }

    /**
     * The variables of a job's event as the dispatcher keeps them
     * (JobPayloadBuilder, TemplateRenderer::buildVariables()).
     *
     * @return array<string, string>
     */
    private static function jobVariables(): array
    {
        return [
            'timestamp' => '2026-11-04 10:00:00 UTC',
            'app.url' => self::BASE,
            'job.id' => '42',
            'job.status' => 'failed',
            'job.exit_code' => '2',
            'job.duration' => '83s',
            'job.url' => self::BASE . '/job/view?id=42',
            'job.template_id' => '7',
            'template.id' => '7',
            'template.name' => 'Deploy',
            'project.id' => '3',
            'project.name' => 'Infra',
            'launched_by' => 'alice',
            'event' => 'job.failed',
            'severity' => 'critical',
            'event_id' => self::EVENT_ID,
            'occurred_at' => (string)self::OCCURRED_AT,
        ];
    }

    /**
     * The run's context of a step that waits for an approval (RunContext).
     *
     * @return array<string, string>
     */
    private static function runVariables(): array
    {
        return [
            'workflow.id' => '12',
            'workflow.status' => 'running',
            'workflow.template_id' => '8',
            'workflow.template_name' => 'Release',
            'workflow.url' => self::BASE . '/workflow-job/view?id=12',
            'workflow.steps_done' => '1',
            'workflow.steps_total' => '3',
            'workflow.reason' => 'Job #42 of step "Deploy" waits for approval.',
            'step.id' => '31',
            'step.job_id' => '42',
            'step.execution_id' => '31',
            'step.workflow_step_id' => '17',
            'step.position' => '2',
            'step.name' => 'Deploy',
            'step.type' => 'job',
            'step.waiting_for' => 'approval',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function job(): array
    {
        return [
            'id' => 42,
            'status' => 'failed',
            'job_template_id' => 7,
            'template_name' => 'Deploy',
            'inventory_id' => null,
            'inventory_name' => null,
            'launched_by' => 'alice',
            'exit_code' => 2,
            'url' => self::BASE . '/job/view?id=42',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflow(): array
    {
        return [
            'id' => 12,
            'workflow_template_id' => 8,
            'template_name' => 'Release',
            'status' => 'running',
            'steps_done' => 1,
            'steps_total' => 3,
            'reason' => 'Job #42 of step "Deploy" waits for approval.',
            'url' => self::BASE . '/workflow-job/view?id=12',
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function step(array $override): array
    {
        return array_merge([
            'id' => 31,
            'workflow_step_id' => 17,
            'position' => 2,
            'step_name' => 'Deploy',
            'step_type' => 'job',
            'job_id' => 42,
            'approval_request_id' => null,
            'waiting_for' => 'approval',
        ], $override);
    }
}

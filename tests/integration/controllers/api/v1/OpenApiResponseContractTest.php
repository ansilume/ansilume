<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\SchedulesController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowStep;
use app\tests\integration\controllers\WebControllerTestCase;
use Symfony\Component\Yaml\Yaml;
use yii\base\Module;

/**
 * The response schemas in web/openapi.yaml list exactly the fields the API
 * returns, with matching types, for job templates, schedules and workflow
 * templates.
 *
 * Regression: the API returns whom a schedule or trigger runs as
 * (created_by of schedules and job templates, has_trigger_token and
 * trigger_user_id of job and workflow templates), and the spec did not
 * document these fields, so API clients could not rely on them.
 *
 * Regression: the trigger fields went to every caller who may view the
 * template. Only callers who may change it get them now, so the spec
 * documents them as optional and says who gets them.
 */
class OpenApiResponseContractTest extends WebControllerTestCase
{
    private const SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** Returned only to callers who may change the job or workflow template. */
    private const TRIGGER_FIELDS = ['has_trigger_token', 'trigger_user_id'];

    private User $admin;
    private User $operator;

    /** @var array<mixed>|null components.schemas of the spec, parsed once per test */
    private ?array $schemas = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('contract-admin', 'admin');
        $this->operator = $this->userWithRole('contract-operator', 'operator');
        $this->loginAs($this->admin);
    }

    public function testJobTemplateSchemaMatchesTheResponseToACallerWhoMayChangeIt(): void
    {
        $template = $this->jobTemplate();
        $template->generateTriggerToken((int)$this->operator->id);

        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
        $data = $this->data($ctrl->actionView((int)$template->id));

        $this->assertMatchesSchema('JobTemplate', $data);
        $this->assertSame((int)$this->admin->id, $data['created_by']);
        $this->assertTrue($data['has_trigger_token']);
        $this->assertSame((int)$this->operator->id, $data['trigger_user_id'], 'A trigger runs as the user who generated its token.');
    }

    public function testJobTemplateSchemaMatchesTheResponseToACallerWhoMayOnlyViewIt(): void
    {
        $template = $this->jobTemplate();
        $template->generateTriggerToken((int)$this->operator->id);
        $this->loginAs($this->userWithRole('contract-viewer', 'viewer'));

        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
        $data = $this->data($ctrl->actionView((int)$template->id));

        $this->assertMatchesSchema('JobTemplate', $data, self::TRIGGER_FIELDS);
        $this->assertDocumentedAsOnlyForCallersWhoMayChange('JobTemplate', 'template');
    }

    public function testScheduleSchemaMatchesTheResponse(): void
    {
        $schedule = new Schedule();
        $schedule->name = 'contract-schedule';
        $schedule->job_template_id = (int)$this->jobTemplate()->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->operator->id;
        $this->assertTrue($schedule->save(false));

        $ctrl = new SchedulesController('api/v1/schedules', $this->app());
        $data = $this->data($ctrl->actionView((int)$schedule->id));

        $this->assertMatchesSchema('Schedule', $data);
        $this->assertSame((int)$this->operator->id, $data['created_by'], 'A schedule runs as its creator.');
    }

    public function testWorkflowTemplateSchemasMatchTheResponsesToACallerWhoMayChangeIt(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->jobTemplate()->id);
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());

        $detail = $this->data($ctrl->actionView((int)$workflow->id));
        $this->assertMatchesSchema('WorkflowTemplateDetail', $detail);
        $this->assertFalse($detail['has_trigger_token']);
        $this->assertNull($detail['trigger_user_id'], 'Without a token no trigger runs.');
        $steps = $detail['steps'];
        $this->assertIsArray($steps);
        $this->assertCount(1, $steps);
        $this->assertIsArray($steps[0]);
        $this->assertMatchesSchema('WorkflowStep', $steps[0]);

        $this->assertMatchesSchema('WorkflowTemplate', $this->listItem($ctrl->actionIndex(), (int)$workflow->id));
    }

    public function testWorkflowTemplateSchemasMatchTheResponsesToACallerWhoMayOnlyViewIt(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->jobTemplate()->id);
        $workflow->generateTriggerToken((int)$this->operator->id);
        $this->loginAs($this->userWithRole('contract-viewer', 'viewer'));
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());

        $this->assertMatchesSchema('WorkflowTemplateDetail', $this->data($ctrl->actionView((int)$workflow->id)), self::TRIGGER_FIELDS);
        $this->assertMatchesSchema('WorkflowTemplate', $this->listItem($ctrl->actionIndex(), (int)$workflow->id), self::TRIGGER_FIELDS);
        $this->assertDocumentedAsOnlyForCallersWhoMayChange('WorkflowTemplate', 'workflow');
    }

    /**
     * The spec file under test.
     */
    protected function specPath(): string
    {
        return dirname(__DIR__, 5) . '/web/openapi.yaml';
    }

    /**
     * $data has exactly the documented properties of $schema except
     * $absent, each of the documented type (null only where the property is
     * nullable). The $absent properties are documented, but not required.
     *
     * @param array<mixed> $data
     * @param list<string> $absent
     */
    private function assertMatchesSchema(string $schema, array $data, array $absent = []): void
    {
        $properties = $this->properties($schema);
        $required = $this->required($schema);
        foreach ($absent as $name) {
            $this->assertArrayHasKey($name, $properties, "Schema {$schema} in web/openapi.yaml must document {$name}.");
            $this->assertNotContains($name, $required, "{$schema}.{$name} is not returned to every caller, so it must not be required.");
            unset($properties[$name]);
        }
        $documented = array_keys($properties);
        $returned = array_map('strval', array_keys($data));
        sort($documented);
        sort($returned);
        $this->assertSame($documented, $returned, "Schema {$schema} in web/openapi.yaml must list exactly the fields the API returns.");

        foreach ($properties as $name => $definition) {
            $type = $definition['type'] ?? null;
            if (!is_string($type)) {
                // oneOf or $ref: no single type to compare.
                continue;
            }
            $nullable = ($definition['nullable'] ?? false) === true;
            $this->assertTrue(
                self::hasType($data[$name], $type, $nullable),
                "{$schema}.{$name} is documented as {$type}" . ($nullable ? ' or null' : '') . ', the API returned ' . get_debug_type($data[$name]) . '.'
            );
        }
    }

    /**
     * The properties of a schema, with those of the schemas it extends
     * through allOf.
     *
     * @return array<string, array<mixed>>
     */
    private function properties(string $schema): array
    {
        $definition = $this->schema($schema);
        $parts = $definition['allOf'] ?? [$definition];
        $this->assertIsArray($parts);

        $properties = [];
        foreach ($parts as $part) {
            $this->assertIsArray($part);
            $ref = $part['$ref'] ?? null;
            $properties += is_string($ref)
                ? $this->properties(substr($ref, strlen(self::SCHEMA_REF_PREFIX)))
                : $this->ownProperties($part);
        }

        return $properties;
    }

    /**
     * The required properties of a schema, with those of the schemas it
     * extends through allOf.
     *
     * @return list<string>
     */
    private function required(string $schema): array
    {
        $definition = $this->schema($schema);
        $parts = $definition['allOf'] ?? [$definition];
        $this->assertIsArray($parts);

        $required = [];
        foreach ($parts as $part) {
            $this->assertIsArray($part);
            $ref = $part['$ref'] ?? null;
            $own = is_string($ref) ? $this->required(substr($ref, strlen(self::SCHEMA_REF_PREFIX))) : ($part['required'] ?? []);
            $this->assertIsArray($own);
            $required = array_merge($required, array_map('strval', array_values($own)));
        }

        return $required;
    }

    /**
     * The descriptions of the trigger fields of $schema say that only
     * callers who may change the $noun get them.
     */
    private function assertDocumentedAsOnlyForCallersWhoMayChange(string $schema, string $noun): void
    {
        $properties = $this->properties($schema);
        foreach (self::TRIGGER_FIELDS as $name) {
            $description = $properties[$name]['description'] ?? null;
            $this->assertIsString($description, "{$schema}.{$name}");
            $this->assertStringContainsStringIgnoringCase(
                "only callers who may change the {$noun}",
                (string)preg_replace('/\s+/', ' ', $description),
                "{$schema}.{$name} must say who gets it."
            );
        }
    }

    /**
     * @param array<mixed> $definition
     * @return array<string, array<mixed>>
     */
    private function ownProperties(array $definition): array
    {
        $own = $definition['properties'] ?? [];
        $this->assertIsArray($own);

        $properties = [];
        foreach ($own as $name => $property) {
            $this->assertIsArray($property);
            $properties[(string)$name] = $property;
        }

        return $properties;
    }

    /**
     * @return array<mixed>
     */
    private function schema(string $name): array
    {
        if ($this->schemas === null) {
            $spec = Yaml::parseFile($this->specPath());
            $this->assertIsArray($spec);
            $components = $spec['components'] ?? null;
            $this->assertIsArray($components);
            $schemas = $components['schemas'] ?? null;
            $this->assertIsArray($schemas);
            $this->schemas = $schemas;
        }
        $schema = $this->schemas[$name] ?? null;
        $this->assertIsArray($schema, "web/openapi.yaml has no schema {$name}.");

        return $schema;
    }

    private static function hasType(mixed $value, string $type, bool $nullable): bool
    {
        if ($value === null) {
            return $nullable;
        }

        return match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $result
     * @return array<mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * The item with $id from a paginated list response.
     *
     * @param array<string, mixed> $result
     * @return array<mixed>
     */
    private function listItem(array $result, int $id): array
    {
        foreach ($this->data($result) as $item) {
            $this->assertIsArray($item);
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        $this->fail("Item #{$id} is missing from the list.");
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }

    private function jobTemplate(): JobTemplate
    {
        $adminId = (int)$this->admin->id;

        return $this->createJobTemplate(
            (int)$this->createProject($adminId)->id,
            (int)$this->createInventory($adminId)->id,
            (int)$this->createRunnerGroup($adminId)->id,
            $adminId
        );
    }

    private function userWithRole(string $suffix, string $role): User
    {
        $user = $this->createUser($suffix);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $item = $auth->getRole($role);
        $this->assertNotNull($item);
        $auth->assign($item, (string)$user->id);

        return $user;
    }
}

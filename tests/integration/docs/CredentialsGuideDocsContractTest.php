<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\SchedulesController;
use app\controllers\InventoryController;
use app\controllers\JobController;
use app\controllers\JobTemplateController;
use app\controllers\ScheduleController;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/credentials.md, **Secrets in inventories and extra vars**, says what
 * the code does: the content of a static inventory and the extra vars of job
 * templates, jobs and schedules are shown on their pages to everyone who may
 * see them (the access rules of their controllers, and the roles that hold
 * those permissions in the test database), the content of an inventory also
 * through GET /api/v1/inventories/{id} (the InventoryDetail schema, since
 * 2.12.0, which InventoryView extends), and the extra vars of jobs and
 * schedules also through the API (the Job and Schedule schemas of
 * web/openapi.yaml, which the response contract tests compare with the
 * answers), while the list of inventories and the job templates of the API
 * leave them out; and it tells where to keep secrets instead.
 */
class CredentialsGuideDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    private const SECTION = '## Secrets in inventories and extra vars';

    public function testTheGuideSaysWhoSeesInventoryContentAndExtraVarsAndWhereSecretsGo(): void
    {
        $section = $this->flat('docs/credentials.md', self::SECTION);
        $expected = [
            'The content of a static inventory and the extra vars of job templates, jobs and schedules are stored as entered, without encryption, and shown to everyone who may see them:',
            'the content on the page of the inventory and in `GET /api/v1/inventories/{id}`, a job template\'s Default Extra Vars on its page, a job\'s extra vars on its page, in its stored command and in `GET /api/v1/jobs`, and a schedule\'s extra vars on its page and in `GET /api/v1/schedules`.',
            'By default viewers see inventories, job templates and jobs, and operators schedules; with teams, the members of the teams that have their project do.',
            'only the answers of password survey fields show as `$encrypted$`.',
            '- a credential, which no page and no API answer shows: a token credential reaches the playbook as an environment variable',
            '- a vault-encrypted value, which the job template\'s vault password opens on the runner',
            '- a password survey field, whose answers show as `$encrypted$`',
            'give the project an SCM credential instead, which no page, log or checkout shows',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $section, 'credentials.md');
        }
    }

    /**
     * The pages need the permissions the guide's "By default" names, and the
     * built-in roles hold them so.
     */
    public function testThePagesNeedThePermissionsTheBuiltInRolesHoldAsTheGuideSays(): void
    {
        $pages = [
            'inventory.view' => [InventoryController::class, 'view'],
            'job-template.view' => [JobTemplateController::class, 'view'],
            'job.view' => [JobController::class, 'view'],
            'job.launch' => [ScheduleController::class, 'view'],
        ];
        foreach ($pages as $permission => [$class, $action]) {
            $this->assertSame([$permission], $this->rolesOf($class, $action), "{$class} {$action}");
        }
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $viewer = array_keys($auth->getPermissionsByRole('viewer'));
        foreach (['inventory.view', 'job-template.view', 'job.view'] as $permission) {
            $this->assertContains($permission, $viewer, "Viewers see what needs {$permission}.");
        }
        $this->assertNotContains('job.launch', $viewer, 'Viewers do not see schedules.');
        $this->assertContains('job.launch', array_keys($auth->getPermissionsByRole('operator')), 'Operators see schedules.');
        foreach ([JobsController::class => 'job.view', SchedulesController::class => 'job.launch'] as $class => $permission) {
            $controller = new \ReflectionClass($class);
            $rules = $controller->getMethod('apiAccessRules')->invoke($controller->newInstanceWithoutConstructor());
            $this->assertIsArray($rules);
            $this->assertSame($permission, $rules['index'] ?? null, "{$class} index");
            $this->assertSame($permission, $rules['view'] ?? null, "{$class} view");
        }
    }

    /**
     * The pages write the values the guide names, and the API answers the
     * extra vars of jobs (also in the stored command) and schedules, and the
     * content of an inventory in its detail (GET /api/v1/inventories/{id}),
     * not in the list of inventories, nor the extra vars of job templates.
     */
    public function testThePagesAndTheApiShowWhatTheGuideSays(): void
    {
        $root = dirname(__DIR__, 3);
        $views = [
            'views/inventory/view.php' => 'Html::encode($model->content)',
            'views/job-template/view.php' => 'Html::encode((string)$model->getMaskedExtraVars())',
            'views/job/view.php' => 'Html::encode($job->extra_vars)',
            'views/schedule/view.php' => 'ScheduleSecrets::displayExtraVars($model)',
        ];
        foreach ($views as $view => $writes) {
            $this->assertStringContainsString($writes, (string)file_get_contents($root . '/' . $view), "{$view} shows what the guide says.");
        }
        $this->assertStringContainsString('Html::encode($job->execution_command)', (string)file_get_contents($root . '/views/job/view.php'));
        $spec = Yaml::parseFile($root . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $properties = static fn (string $schema): array => array_keys((array)($spec['components']['schemas'][$schema]['properties'] ?? []));
        $this->assertContains('extra_vars', $properties('Job'));
        $this->assertContains('execution_command', $properties('Job'));
        $this->assertContains('extra_vars', $properties('Schedule'));
        $this->assertNotContains('content', $properties('Inventory'));
        $detail = (array)($spec['components']['schemas']['InventoryDetail']['allOf'] ?? []);
        $this->assertSame(['$ref' => '#/components/schemas/Inventory'], $detail[0] ?? null, 'The detail is the inventory of the list ...');
        $this->assertContains('content', array_keys((array)($detail[1]['properties'] ?? [])), '... with its content.');
        $this->assertSame(
            ['$ref' => '#/components/responses/InventoryViewResponse'],
            $spec['paths']['/api/v1/inventories/{id}']['get']['responses']['200'] ?? null,
            'GET /api/v1/inventories/{id} answers InventoryView ...'
        );
        $this->assertSame(
            '#/components/schemas/InventoryView',
            $spec['components']['responses']['InventoryViewResponse']['content']['application/json']['schema']['properties']['data']['$ref'] ?? null
        );
        $this->assertContains(
            ['$ref' => '#/components/schemas/InventoryDetail'],
            (array)($spec['components']['schemas']['InventoryView']['allOf'] ?? []),
            '... which is the detail, with its content.'
        );
        $this->assertNotContains('extra_vars', $properties('JobTemplate'));
    }

    /**
     * The roles of the access rules of $class that allow the action $id.
     *
     * @param class-string $class
     * @return list<string>
     */
    private function rolesOf(string $class, string $id): array
    {
        $controller = new \ReflectionClass($class);
        $rules = $controller->getMethod('accessRules')->invoke($controller->newInstanceWithoutConstructor());
        $this->assertIsArray($rules);
        $roles = [];
        foreach ($rules as $rule) {
            if (in_array($id, (array)($rule['actions'] ?? []), true) && ($rule['allow'] ?? false) === true) {
                $roles = array_merge($roles, array_map('strval', (array)($rule['roles'] ?? [])));
            }
        }

        return $roles;
    }
}

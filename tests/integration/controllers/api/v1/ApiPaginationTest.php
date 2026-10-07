<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\BaseApiController;
use app\models\ApiToken;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Every paginated REST list reads ?page= as a page number.
 *
 * Regression: the list actions passed the raw query parameter, a string, to
 * BaseApiController::paginated(int $page). Under strict_types that is a
 * TypeError, so every list answered ?page=2 with a server error, and
 * operators could not page through e.g. the job templates with a warning.
 */
class ApiPaginationTest extends WebControllerTestCase
{
    /**
     * @return array<string, array{0: class-string<BaseApiController>}>
     */
    public static function listControllerProvider(): array
    {
        $controllers = [
            'ApprovalRules', 'Approvals', 'AuditLogs', 'Credentials', 'Inventories', 'JobTemplates', 'Jobs',
            'NotificationTemplates', 'Projects', 'RunnerGroups', 'Runners', 'Schedules', 'Teams', 'Users',
            'Webhooks', 'WorkflowJobs', 'WorkflowTemplates',
        ];
        $cases = [];
        foreach ($controllers as $name) {
            /** @var class-string<BaseApiController> $class */
            $class = 'app\\controllers\\api\\v1\\' . $name . 'Controller';
            $cases[$name] = [$class];
        }

        return $cases;
    }

    /**
     * @dataProvider listControllerProvider
     * @param class-string<BaseApiController> $class
     */
    public function testASecondPageIsServed(string $class): void
    {
        $this->authenticateWithAdmin();
        $this->setQueryParams(['page' => '2']);

        $result = $this->listOf($class);

        $this->assertSame(2, $result['meta']['page'] ?? null);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusablePageProvider(): array
    {
        return [
            'not a number' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-3'],
            'empty' => [''],
            'array' => [['2']],
        ];
    }

    /**
     * @dataProvider unusablePageProvider
     */
    public function testAnUnusablePageMeansTheFirst(mixed $page): void
    {
        $this->authenticateWithAdmin();
        $this->setQueryParams(['page' => $page]);

        $result = $this->listOf(\app\controllers\api\v1\ProjectsController::class);

        $this->assertSame(1, $result['meta']['page'] ?? null);
    }

    /**
     * @param class-string<BaseApiController> $class
     * @return array<string, mixed>
     */
    private function listOf(string $class): array
    {
        $controller = new $class('api/v1/list', \Yii::$app);
        $this->assertTrue(method_exists($controller, 'actionIndex'));
        /** @var array<string, mixed> $result */
        $result = $controller->actionIndex();
        $this->assertArrayHasKey('meta', $result, (string)json_encode($result));

        return $result;
    }

    private function authenticateWithAdmin(): void
    {
        $user = $this->createUser('api-pagination-admin');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $adminRole = $auth->getRole('admin');
        $this->assertNotNull($adminRole);
        $auth->assign($adminRole, (string)$user->id);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'pagination-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }
}

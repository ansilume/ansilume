<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\BaseApiController;
use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\JobController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Project;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\controllers\CapturesParams;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use Symfony\Component\Yaml\Yaml;
use yii\data\ActiveDataProvider;
use yii\data\DataProviderInterface;

/**
 * Every paginated REST list reads ?page= as a page number, serves the rows
 * of that page, and answers a page past the last with no rows.
 *
 * Regression: the list actions passed the raw query parameter, a string, to
 * BaseApiController::paginated(int $page). Under strict_types that is a
 * TypeError, so every list answered ?page=2 with a server error, and
 * operators could not page through e.g. the job templates with a warning.
 *
 * Regression: up to 2.13.1 a page past the last served the rows of the last
 * page again, with the requested page in meta.page: yii\data\Pagination
 * moves a page past the last back to the last (validatePage). A client that
 * pages until the data is empty never stopped, nor did one that pages until
 * a page is short when the last page is full.
 */
class ApiPaginationTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

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
     * @return array<string, array{0: string}>
     */
    public static function textOfTheSecondPageProvider(): array
    {
        return [
            'fraction' => ['2.5'],
            'exponent' => ['2e0'],
            'leading space' => [' 2'],
            'trailing space' => ['2 '],
            'digits and text' => ['2abc'],
            'leading zero' => ['02'],
            'plus sign' => ['+2'],
        ];
    }

    /**
     * Up to the last page, meta.page is the page whose rows the list serves:
     * requestedPage() reads the query text with the (int) cast with which
     * yii\data\Pagination reads it for the web lists, and the list serves
     * the rows of that page (BaseApiController::modelsOfPage()). A page past
     * the last has no rows (testRegressionAPagePastTheLastIsEmpty()).
     *
     * Regression: during the move to PHP 8.5 requestedPage() read ?page=2.5
     * as page 1 while the rows were those of page 2; ?page=2abc did the same
     * up to 2.13.1.
     *
     * @dataProvider textOfTheSecondPageProvider
     */
    public function testThePageOfMetaIsThePageOfTheRows(string $page): void
    {
        $user = $this->authenticateWithAdmin();
        for ($i = 0; $i < 26; $i++) {
            $this->createProject((int)$user->id);
        }
        $this->setQueryParams(['page' => '2']);
        $secondPage = $this->listOf(\app\controllers\api\v1\ProjectsController::class);
        $this->assertNotSame([], $secondPage['data'] ?? []);

        $this->setQueryParams(['page' => $page]);
        $result = $this->listOf(\app\controllers\api\v1\ProjectsController::class);

        $this->assertSame(2, $result['meta']['page'] ?? null);
        $this->assertSame($secondPage['data'], $result['data'] ?? null);
    }

    /**
     * Regression: ?page=3 of 26 projects (two pages) answered the project
     * of page 2 again, labelled page 3. It answers no rows now, with the
     * requested page and the list's total and pages in meta.
     */
    public function testRegressionAPagePastTheLastIsEmpty(): void
    {
        $this->createProjects($this->authenticateWithAdmin(), 26);
        $lastPage = $this->projectsOn('2');
        $this->assertCount(1, $lastPage['data']);

        $result = $this->projectsOn('3');

        $this->assertSame([], $result['data']);
        $this->assertSame(['total' => 26, 'page' => 3, 'per_page' => 25, 'pages' => 2], $result['meta']);
    }

    /**
     * Regression: with a full last page (50 projects, two pages of 25),
     * ?page=3 answered the 25 projects of page 2 again, so a client that
     * pages until a page is short never stopped either.
     */
    public function testRegressionAPagePastAFullLastPageIsEmpty(): void
    {
        $this->createProjects($this->authenticateWithAdmin(), 50);
        $lastPage = $this->projectsOn('2');
        $this->assertCount(25, $lastPage['data']);

        $result = $this->projectsOn('3');

        $this->assertSame([], $result['data']);
        $this->assertSame(['total' => 50, 'page' => 3, 'per_page' => 25, 'pages' => 2], $result['meta']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function pageFarPastTheLastProvider(): array
    {
        return [
            'far past the last' => ['99', 99],
            'exponent' => ['1e3', 1000],
            'the largest int' => ['9223372036854775807', PHP_INT_MAX],
            'digits beyond the int range' => ['9223372036854775808', PHP_INT_MAX],
            'exponent beyond the int range' => ['1e30', PHP_INT_MAX],
        ];
    }

    /**
     * Regression: a page far past the last answered the rows of the last
     * page too, labelled with the requested page, up to PHP_INT_MAX. It
     * answers no rows now and no error: the total count decides that it is
     * past the last, so its offset, which multiplied by the page size passes
     * the int range, never reaches the query.
     *
     * @dataProvider pageFarPastTheLastProvider
     */
    public function testRegressionAPageFarPastTheLastIsEmpty(string $page, int $metaPage): void
    {
        $this->createProjects($this->authenticateWithAdmin(), 26);

        $result = $this->projectsOn($page);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([], $result['data']);
        $this->assertSame(['total' => 26, 'page' => $metaPage, 'per_page' => 25, 'pages' => 2], $result['meta']);
    }

    /**
     * Regression: every list served the rows of its last page again for a
     * page past the last. Each list has rows here (one of every kind besides
     * what the test database holds), its last page serves rows, and the
     * page after it none, with the requested page and the list's total and
     * pages in meta.
     *
     * @dataProvider listControllerProvider
     * @param class-string<BaseApiController> $class
     */
    public function testRegressionEveryListAnswersAPagePastTheLastWithNoRows(string $class): void
    {
        $this->seedOneOfEveryKind($this->authenticateWithAdmin());
        $meta = $this->listOf($class)['meta'];
        $this->assertIsArray($meta);
        $pages = $meta['pages'] ?? null;
        $this->assertIsInt($pages);
        $this->assertGreaterThan(0, $pages, 'The list must have rows, or a page past the last has none anyway.');
        $this->setQueryParams(['page' => (string)$pages]);
        $this->assertNotSame([], $this->listOf($class)['data'], 'The last page serves rows.');

        $this->setQueryParams(['page' => (string)($pages + 1)]);
        $result = $this->listOf($class);

        $this->assertSame([], $result['data']);
        $this->assertSame(array_replace($meta, ['page' => $pages + 1]), $result['meta']);
    }

    /**
     * modelsOfPage() decides from the total count, before any row is read,
     * that a page is past the last: no row query runs for it, so a page up
     * to PHP_INT_MAX never becomes an offset. Up to the last page it reads
     * the rows of the page asked for, and a page below 1 is the first, as
     * in paginated().
     */
    public function testAPagePastTheLastReadsNoRows(): void
    {
        $this->createProjects($this->authenticateWithAdmin(), 26);
        $lists = $this->listStub();
        foreach ([3, 99, PHP_INT_MAX] as $page) {
            $provider = $this->projectProvider();

            $this->assertSame([], $lists->modelsOf($provider, $page), "page {$page}");
            $this->assertSame(0, $provider->rowReads, "page {$page} reads no rows");
        }

        $provider = $this->projectProvider();
        $lastPage = $lists->modelsOf($provider, 2);
        $this->assertSame(1, $provider->rowReads);
        $this->assertSame(array_column($this->projectsOn('2')['data'], 'id'), $this->idsOf($lastPage));
        $firstPage = $this->idsOf($lists->modelsOf($this->projectProvider(), 1));
        $this->assertCount(25, $firstPage);
        $this->assertSame($firstPage, $this->idsOf($lists->modelsOf($this->projectProvider(), 0)));
        $this->assertSame($firstPage, $this->idsOf($lists->modelsOf($this->projectProvider(), PHP_INT_MIN)));
    }

    /**
     * A list's data provider must page: without pagination modelsOfPage()
     * could not tell which rows a page holds, and meta would not describe
     * the rows.
     */
    public function testAListWithoutPaginationIsRefused(): void
    {
        $provider = new ActiveDataProvider(['query' => Project::find(), 'pagination' => false]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(BaseApiController::UNPAGED_LIST);

        $this->listStub()->modelsOf($provider, 1);
    }

    /**
     * The web job list keeps its pager: JobSearchForm::search() serves the
     * job list of the API and the web's, and only the API answers a page
     * past the last with no rows. The web list shows its last page, as
     * yii\data\Pagination moves the page there (validatePage) and the
     * pager expects.
     */
    public function testTheWebJobListStillShowsItsLastPagePastTheLast(): void
    {
        $user = $this->authenticateWithAdmin();
        $userId = (int)$user->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        for ($i = 0; $i < 26; $i++) {
            $this->createJob((int)$template->id, $userId);
        }
        $this->setQueryParams(['page' => '2']);
        $webLastPage = $this->idsOf($this->webJobList()->getModels());
        $this->assertCount(1, $webLastPage);

        $this->setQueryParams(['page' => '3']);
        $this->assertSame([], $this->listOf(JobsController::class)['data']);
        $webPastTheLast = $this->webJobList();

        $this->assertSame($webLastPage, $this->idsOf($webPastTheLast->getModels()));
        $pagination = $webPastTheLast->getPagination();
        $this->assertNotFalse($pagination);
        $this->assertSame(1, $pagination->getPage(), 'The pager marks page 2, the last.');
    }

    /**
     * Every list of the API takes its rows from modelsOfPage(), and the
     * cases above cover each list: a list that read the rows of its data
     * provider itself (getModels()) would answer a page past the last with
     * the rows of the last page again.
     */
    public function testEveryListOfTheApiTakesItsRowsFromModelsOfPage(): void
    {
        $lists = [];
        $ownRowReads = [];
        $files = glob(dirname(__DIR__, 5) . '/controllers/api/v1/*Controller.php');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $name = basename($file, 'Controller.php');
            if ($name === 'BaseApi') {
                // modelsOfPage() itself, the one place that reads the rows.
                continue;
            }
            $lines = file($file);
            $this->assertIsArray($lines);
            foreach (preg_grep('/->getModels\(|->models\b/', $lines) ?: [] as $index => $line) {
                $ownRowReads[] = "{$name}Controller.php:" . ($index + 1) . ': ' . trim($line);
            }
            $code = implode('', $lines);
            if (str_contains($code, '$this->paginated(')) {
                $this->assertStringContainsString('$this->modelsOfPage(', $code, "{$name}Controller pages a list.");
                $lists[] = $name;
            }
        }
        $covered = array_keys(self::listControllerProvider());
        sort($covered);
        sort($lists);

        $this->assertSame([], $ownRowReads, 'These read the rows of a data provider themselves: take them from modelsOfPage().');
        $this->assertSame($covered, $lists, 'listControllerProvider() names every list.');
    }

    /**
     * web/openapi.yaml says what a page past the last answers.
     */
    public function testTheSpecSaysWhatAPagePastTheLastAnswers(): void
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 5) . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $page = $spec['components']['parameters']['Page']['description'] ?? null;
        $metaPage = $spec['components']['schemas']['PaginationMeta']['properties']['page']['description'] ?? null;
        $this->assertIsString($page);
        $this->assertIsString($metaPage);

        $this->assertStringContainsString(
            'A page past the last (`meta.pages`) answers 200 with an empty `data`',
            (string)preg_replace('/\s+/', ' ', $page)
        );
        $this->assertStringContainsString(
            'The requested page (`page`), also one past the last',
            (string)preg_replace('/\s+/', ' ', $metaPage)
        );
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

    /**
     * GET /api/v1/projects?page=$page.
     *
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    private function projectsOn(string $page): array
    {
        $this->setQueryParams(['page' => $page]);
        $result = $this->listOf(ProjectsController::class);
        $this->assertIsArray($result['data'] ?? null);
        $this->assertIsArray($result['meta']);

        return ['data' => $result['data'], 'meta' => $result['meta']];
    }

    private function createProjects(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createProject((int)$user->id);
        }
    }

    /**
     * One row of every list for $admin, who sees every row: so that each
     * list has a last page with rows.
     */
    private function seedOneOfEveryKind(User $admin): void
    {
        $userId = (int)$admin->id;
        $group = $this->createRunnerGroup($userId);
        $this->createRunner((int)$group->id, $userId);
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$group->id,
            $userId
        );
        $request = new ApprovalRequest();
        $request->job_id = (int)$this->createJob((int)$template->id, $userId)->id;
        $request->approval_rule_id = (int)$this->createApprovalRule($userId)->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $this->assertTrue($request->save(false));
        $schedule = new Schedule();
        $schedule->name = 'api-pagination-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $userId;
        $this->assertTrue($schedule->save(false));
        $log = new AuditLog();
        $log->user_id = $userId;
        $log->action = 'api-pagination.seeded';
        $log->created_at = time();
        $this->assertTrue($log->save(false));
        $this->createCredential($userId);
        $this->createNotificationTemplate($userId);
        $this->createTeam($userId);
        $this->createWebhook($userId);
        $this->createWorkflowJob((int)$this->createWorkflowTemplate($userId)->id, $userId);
    }

    /**
     * The projects of GET /api/v1/projects, newest first, through a data
     * provider that counts in $rowReads how often it reads rows.
     */
    private function projectProvider(): ActiveDataProvider
    {
        return new class ([
            'query' => Project::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]) extends ActiveDataProvider {
            public int $rowReads = 0;

            protected function prepareModels()
            {
                $this->rowReads++;

                return parent::prepareModels();
            }
        };
    }

    /**
     * A controller that hands out BaseApiController::modelsOfPage() as
     * modelsOf().
     */
    private function listStub(): BaseApiController
    {
        return new class ('api/v1/stub', \Yii::$app) extends BaseApiController {
            protected function apiAccessRules(): array
            {
                return [];
            }

            /**
             * @return array<int, mixed>
             */
            public function modelsOf(DataProviderInterface $provider, int $page): array
            {
                return $this->modelsOfPage($provider, $page);
            }
        };
    }

    /**
     * The data provider the web job list (/job/index) renders.
     */
    private function webJobList(): ActiveDataProvider
    {
        $controller = new class ('job', \Yii::$app) extends JobController {
            use CapturesParams;
        };
        $controller->actionIndex();
        $provider = $controller->capturedParams['dataProvider'] ?? null;
        $this->assertInstanceOf(ActiveDataProvider::class, $provider);

        return $provider;
    }

    /**
     * @param array<int, mixed> $models
     * @return list<int>
     */
    private function idsOf(array $models): array
    {
        return array_values(array_map(static fn ($model): int => (int)$model->id, $models));
    }

    private function authenticateWithAdmin(): User
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

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\AppLinks;
use app\controllers\ApprovalController;
use app\controllers\JobController;
use app\controllers\WorkflowJobController;
use PHPUnit\Framework\TestCase;

/**
 * The links of notifications (AppLinks): from params.appBaseUrl (APP_URL)
 * alone, never from the host of a request, and built without the URL
 * manager, which throws in the console application that the notification
 * worker, the queue worker and the maintenance run are; each opens its page
 * in the web application.
 */
class AppLinksTest extends TestCase
{
    private bool $hadAppBaseUrl = false;

    private mixed $appBaseUrl = null;

    private mixed $request = null;

    private bool $hadAppUrl = false;

    private mixed $appUrl = null;

    protected function setUp(): void
    {
        $this->hadAppBaseUrl = array_key_exists('appBaseUrl', \Yii::$app->params);
        $this->appBaseUrl = \Yii::$app->params['appBaseUrl'] ?? null;
        $this->request = \Yii::$app->getComponents(true)['request'] ?? null;
        $this->hadAppUrl = array_key_exists('APP_URL', $_ENV);
        $this->appUrl = $_ENV['APP_URL'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadAppBaseUrl) {
            \Yii::$app->params['appBaseUrl'] = $this->appBaseUrl;
        } else {
            unset(\Yii::$app->params['appBaseUrl']);
        }
        \Yii::$app->set('request', $this->request);
        if ($this->hadAppUrl) {
            $_ENV['APP_URL'] = $this->appUrl;
        } else {
            unset($_ENV['APP_URL']);
        }
    }

    /**
     * The links of a notification that the console builds (the worker, the
     * maintenance run, a project sync): absolute from appBaseUrl, its
     * trailing slash dropped. Url::to() would throw here: the console has
     * no host and no script URL.
     */
    public function testLinksAreAbsoluteFromAppBaseUrlInTheConsole(): void
    {
        \Yii::$app->params['appBaseUrl'] = 'https://ansilume.example.com/';
        \Yii::$app->set('request', new \yii\console\Request());
        $this->assertInstanceOf(\yii\console\Application::class, \Yii::$app);

        $this->assertSame('https://ansilume.example.com', AppLinks::base());
        $this->assertSame('https://ansilume.example.com/workflow-job/view?id=12', AppLinks::workflowRun(12));
        $this->assertSame('https://ansilume.example.com/job/view?id=7', AppLinks::job(7));
        $this->assertSame('https://ansilume.example.com/approval/view?id=3', AppLinks::approval(3));
        try {
            \yii\helpers\Url::to(['/job/view', 'id' => 7], true);
            $this->fail('Url::to() builds no link in the console application.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\yii\base\InvalidConfigException::class, $e);
        }
    }

    /**
     * appBaseUrl wins over the host of the request, which for a runner's
     * report is the internal "nginx" (regression of 2.x: http://nginx
     * reached the notifications).
     */
    public function testAppBaseUrlWinsOverTheHostOfTheRequest(): void
    {
        \Yii::$app->params['appBaseUrl'] = 'https://ansilume.example.com';
        \Yii::$app->set('request', $this->webRequestFrom('http://nginx'));

        $this->assertSame('https://ansilume.example.com/job/view?id=7', AppLinks::job(7));
    }

    /**
     * Without appBaseUrl a link is a path, in a web request as in the
     * console: the host of the request never becomes its base.
     *
     * Regression: with an empty APP_URL= the links took the host of the
     * request in which the event happened, http://nginx for a runner's
     * report, although the notification worker sends outside of any request.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function noAppBaseUrlProvider(): array
    {
        return ['not set' => [null], 'empty' => [''], 'a slash only' => ['/']];
    }

    /**
     * @dataProvider noAppBaseUrlProvider
     */
    public function testRegressionWithoutAppBaseUrlALinkIsAPathInAWebRequestToo(?string $appBaseUrl): void
    {
        if ($appBaseUrl === null) {
            unset(\Yii::$app->params['appBaseUrl']);
        } else {
            \Yii::$app->params['appBaseUrl'] = $appBaseUrl;
        }

        \Yii::$app->set('request', $this->webRequestFrom('http://nginx'));
        $this->assertSame('', AppLinks::base());
        $this->assertSame('/job/view?id=42', AppLinks::job(42));
        $this->assertSame('/approval/view?id=3', AppLinks::approval(3));

        \Yii::$app->set('request', new \yii\console\Request());
        $this->assertSame('', AppLinks::base());
        $this->assertSame('/workflow-job/view?id=12', AppLinks::workflowRun(12));
    }

    /**
     * Regression: an empty APP_URL= in .env gives an empty appBaseUrl
     * (config/params.php), and the links of an event of a web request, such
     * as a job that a runner's report through nginx finished, are paths
     * without an address, as bin/diagnose's warning says. An unset APP_URL
     * keeps its default, http://localhost:8080.
     */
    public function testRegressionAnEmptyAppUrlGivesPathsWithoutAnAddressInAWebRequest(): void
    {
        \Yii::$app->set('request', $this->webRequestFrom('http://nginx'));

        $_ENV['APP_URL'] = '';
        \Yii::$app->params['appBaseUrl'] = $this->params()['appBaseUrl'] ?? null;
        $this->assertSame('', \Yii::$app->params['appBaseUrl']);
        $this->assertSame('/job/view?id=42', AppLinks::job(42));
        $this->assertSame('/workflow-job/view?id=12', AppLinks::workflowRun(12));
        $this->assertSame('/approval/view?id=3', AppLinks::approval(3));

        unset($_ENV['APP_URL']);
        \Yii::$app->params['appBaseUrl'] = $this->params()['appBaseUrl'] ?? null;
        $this->assertSame('http://localhost:8080/job/view?id=42', AppLinks::job(42), 'an unset APP_URL keeps its default');
    }

    /**
     * Each link opens its page: the web application's URL manager reads its
     * path as the route of the page (workflow-job/view, job/view,
     * approval/view), whose action takes the ID of the query.
     *
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function pageProvider(): array
    {
        return [
            'the run' => [AppLinks::WORKFLOW_RUN, WorkflowJobController::class],
            'the job' => [AppLinks::JOB, JobController::class],
            'the approval request' => [AppLinks::APPROVAL, ApprovalController::class],
        ];
    }

    /**
     * @dataProvider pageProvider
     * @param class-string $controller
     */
    public function testEachLinkOpensItsPage(string $path, string $controller): void
    {
        \Yii::$app->params['appBaseUrl'] = 'https://ansilume.example.com';
        $link = 'https://ansilume.example.com' . sprintf($path, 42);
        $parts = parse_url($link);
        $this->assertIsArray($parts);
        parse_str((string)($parts['query'] ?? ''), $query);

        $request = new \yii\web\Request();
        $request->setPathInfo(ltrim((string)($parts['path'] ?? ''), '/'));
        $parsed = $this->webUrlManager()->parseRequest($request);

        $this->assertIsArray($parsed, $link);
        $route = (string)$parsed[0];
        $this->assertSame(['id' => '42'], $query);
        $this->assertSame(
            \yii\helpers\Inflector::camel2id((string)preg_replace('/Controller$/', '', (new \ReflectionClass($controller))->getShortName())) . '/view',
            $route
        );
        $action = new \ReflectionMethod($controller, 'actionView');
        $this->assertSame(['id'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $action->getParameters()));
    }

    private function webRequestFrom(string $hostInfo): \yii\web\Request
    {
        $request = new \yii\web\Request();
        $request->setHostInfo($hostInfo);

        return $request;
    }

    /**
     * The params of config/params.php for the $_ENV of now.
     *
     * @return array<string, mixed>
     */
    private function params(): array
    {
        $params = require dirname(__DIR__, 3) . '/config/params.php';
        $this->assertIsArray($params);

        /** @var array<string, mixed> $params */
        return $params;
    }

    /**
     * The URL manager of the web application (config/web.php).
     */
    private function webUrlManager(): \yii\web\UrlManager
    {
        $config = require dirname(__DIR__, 3) . '/config/web.php';
        $this->assertIsArray($config);
        $manager = new \yii\web\UrlManager($config['components']['urlManager'] + ['cache' => null]);

        return $manager;
    }
}

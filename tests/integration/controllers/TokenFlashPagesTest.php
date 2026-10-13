<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProfileController;
use app\controllers\RunnerController;
use app\controllers\RunnerGroupController;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\Controller;
use yii\web\Response;
use yii\web\View;

/**
 * The pages that show a new token once, as the next request renders them,
 * layout included: the runner group page (the token of a new runner, or a
 * regenerated one) and the API tokens page of the profile (a new API
 * token). Each page reads its data flash with delete=true, and the layout
 * prints only the alert types success, info, warning and danger
 * (FlashAlerts), so the token shows in the page's card and nowhere else.
 */
class TokenFlashPagesTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('token_pages', 'admin');
        $this->loginAs($this->admin);
    }

    /**
     * Regression: the raw runner token was printed a second time by the
     * layout. The page read the flash runner_token without deleting it,
     * and the layout printed every flash that was left: an unstyled
     * alert-runner_token box for each value of the flash, the runner's ID,
     * its name and the raw token.
     */
    public function testRegressionTheRawRunnerTokenWasPrintedASecondTimeByTheLayout(): void
    {
        $group = $this->createRunnerGroup((int)$this->admin->id);
        $this->setPost(['group_id' => (string)$group->id, 'name' => 'token-pages-runner', 'description' => '']);
        $this->runnerController()->actionCreate();
        $token = $this->rawRunnerToken('token-pages-runner');

        $html = $this->runnerGroupPage($group);

        $this->assertRunnerTokenShownOnceInItsCard($html, $token, 'token-pages-runner');
        $this->assertArrayNotHasKey('runner_token', $this->session()->getAllFlashes(), 'the flash is used up');
    }

    public function testARegeneratedRunnerTokenIsShownOnceInItsCardOnly(): void
    {
        $group = $this->createRunnerGroup((int)$this->admin->id);
        $runner = $this->createRunner((int)$group->id, (int)$this->admin->id);
        $this->runnerController()->actionRegenerateToken((int)$runner->id);
        $token = $this->rawRunnerToken($runner->name);

        $html = $this->runnerGroupPage($group);

        $this->assertRunnerTokenShownOnceInItsCard($html, $token, $runner->name);
        $this->assertSame((int)$runner->id, Runner::findByToken($token)?->id, 'the card shows the runner\'s new token');
    }

    public function testTheNextViewOfTheRunnerGroupPageShowsNoToken(): void
    {
        $group = $this->createRunnerGroup((int)$this->admin->id);
        $this->setPost(['group_id' => (string)$group->id, 'name' => 'token-pages-again', 'description' => '']);
        $this->runnerController()->actionCreate();
        $token = $this->rawRunnerToken('token-pages-again');
        $this->runnerGroupPage($group);

        $html = $this->runnerGroupPage($group);

        $this->assertStringNotContainsString($token, $html);
        $this->assertStringNotContainsString('id="token-display"', $html);
    }

    /**
     * Regression: the raw API token was printed a second time by the
     * layout. The page read the flash new_token without deleting it, and
     * the layout printed every flash that was left: the raw token in an
     * unstyled alert-new_token box above the page's card.
     */
    public function testRegressionTheRawApiTokenWasPrintedASecondTimeByTheLayout(): void
    {
        $this->setPost(['name' => 'token-pages-api']);
        $this->profileController()->actionCreateToken();
        $token = $this->flash('new_token');
        $this->assertIsString($token);

        $html = $this->apiTokensPage();

        $this->assertStringContainsString('<code class="user-select-all fs-6">' . $token . '</code>', $html, 'the card shows it');
        $this->assertSame(2, substr_count($html, $token), 'in the card only: its text and its copy button');
        $this->assertSame(2, substr_count($this->card($html, 'New token created.'), $token));
        $this->assertStringNotContainsString('alert-new_token', $html, 'the layout does not print it again');
        $this->assertStringContainsString('Token created. Copy it now — it will not be shown again.', $html, 'the success alert is printed');
        $this->assertArrayNotHasKey('new_token', $this->session()->getAllFlashes(), 'the flash is used up');

        $this->assertStringNotContainsString($token, $this->apiTokensPage(), 'shown once only');
    }

    /**
     * The layout prints the alerts, in the order they were set, and no
     * other flash: neither a type Bootstrap does not style nor a data
     * flash that its page did not read.
     */
    public function testTheLayoutPrintsOnlyTheAlertTypes(): void
    {
        $session = $this->session();
        $session->setFlash('success', 'Saved <b>it</b>.');
        $session->setFlash('error', 'An unstyled box.');
        $session->setFlash('info', 'Note this.');
        $session->setFlash('runner_token', ['runner_id' => 7, 'runner_name' => 'layout-runner', 'raw_token' => 'layout-secret-1']);
        $session->setFlash('warning', 'Careful.');
        $session->setFlash('trigger_token_raw', 'layout-secret-2');
        $session->addFlash('danger', 'First problem.');
        $session->addFlash('danger', 'Second problem.');
        $session->setFlash('custom', 'layout-secret-3');

        $html = $this->apiTokensPage();

        preg_match_all('~<div class="alert alert-([a-z_]+) alert-dismissible fade show" role="alert">\s*(.*?)\s*<button~s', $html, $alerts, PREG_SET_ORDER);
        $this->assertSame(
            [
                ['success', Html::encode('Saved <b>it</b>.')],
                ['info', 'Note this.'],
                ['warning', 'Careful.'],
                ['danger', 'First problem.'],
                ['danger', 'Second problem.'],
            ],
            array_map(static fn (array $alert): array => [$alert[1], $alert[2]], $alerts)
        );
        foreach (['An unstyled box.', 'layout-secret-1', 'layout-runner', 'layout-secret-2', 'layout-secret-3', 'alert-error', 'alert-runner_token', 'alert-custom'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function testTheRunnerGroupListAsksBeforeDeletingAGroup(): void
    {
        $group = $this->createRunnerGroup((int)$this->admin->id);

        $html = $this->page(new RunnerGroupController('runner-group', $this->app()), static fn (RunnerGroupController $c): mixed => $c->actionIndex());

        $this->assertStringContainsString('action="/runner-group/delete?id=' . $group->id . '"', $html);
        $this->assertStringContainsString("onsubmit=\"return confirm('Delete this runner group?')\"", $html);
        $this->assertStringNotContainsString('and all its runners', $html, 'a group with runners is not deleted');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function assertRunnerTokenShownOnceInItsCard(string $html, string $token, string $runnerName): void
    {
        $card = $this->card($html, 'Token for runner');
        $this->assertStringContainsString('Token for runner <strong>' . Html::encode($runnerName) . '</strong>', $card);
        $this->assertMatchesRegularExpression('~id="token-display"\s+value="' . $token . '" readonly>~', $card, 'the token field');
        $this->assertStringContainsString('  -e RUNNER_TOKEN=' . $token . ' \\', $card, 'the docker one-liner');
        $this->assertSame(2, substr_count($html, $token), 'in the card only: the token field and the docker one-liner');
        $this->assertSame(2, substr_count($card, $token));
        $this->assertStringNotContainsString('alert-runner_token', $html, 'the layout does not print it again');
    }

    /**
     * The card that holds $marker: from the opening tag of the alert box
     * around it to the end of that box's first closing </div> after the
     * marker's own block.
     */
    private function card(string $html, string $marker): string
    {
        $at = strpos($html, $marker);
        $this->assertNotFalse($at, "the page shows {$marker}");
        $start = strrpos(substr($html, 0, $at), '<div class="alert');
        $this->assertNotFalse($start);
        $end = strpos($html, "\n</div>", $at);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function rawRunnerToken(string $runnerName): string
    {
        $flash = $this->flash('runner_token');
        $this->assertIsArray($flash);
        $this->assertSame($runnerName, $flash['runner_name'] ?? null);
        $token = $flash['raw_token'] ?? null;
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        return $token;
    }

    /**
     * The flash $key as the action left it. getAllFlashes() marks it for
     * deletion with the next request only: it is still there for the page.
     */
    private function flash(string $key): mixed
    {
        $flashes = $this->session()->getAllFlashes();
        $this->assertArrayHasKey($key, $flashes);

        return $flashes[$key];
    }

    private function runnerGroupPage(RunnerGroup $group): string
    {
        return $this->page(
            new RunnerGroupController('runner-group', $this->app()),
            static fn (RunnerGroupController $c): mixed => $c->actionView((int)$group->id)
        );
    }

    private function apiTokensPage(): string
    {
        return $this->page(new ProfileController('profile', $this->app()), static fn (ProfileController $c): mixed => $c->actionTokens());
    }

    /**
     * The page $action of $controller renders, layout included, as the next
     * request renders it. The console application of the tests has no web
     * root, so asset bundles are dummies; view, asset manager and controller
     * are restored.
     *
     * @template T of Controller
     * @param T $controller
     * @param \Closure(T): mixed $action
     */
    private function page(Controller $controller, \Closure $action): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        \Yii::$app->controller = $controller;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $html = $action($controller);
            $this->assertIsString($html);
            $this->assertStringContainsString('<div id="page-content">', $html, 'rendered with the layout, which prints the flashes');

            return $html;
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    /**
     * RunnerController with its redirect captured instead of sent.
     */
    private function runnerController(): RunnerController
    {
        return new class ('runner', $this->app()) extends RunnerController {
            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    /**
     * ProfileController with its redirect captured instead of sent.
     */
    private function profileController(): ProfileController
    {
        return new class ('profile', $this->app()) extends ProfileController {
            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    private function session(): \yii\web\Session
    {
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;

        return $session;
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}

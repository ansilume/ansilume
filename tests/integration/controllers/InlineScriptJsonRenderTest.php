<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\AnalyticsController;
use app\controllers\ApprovalRuleController;
use app\controllers\CredentialController;
use app\controllers\ProjectController;
use app\models\AnalyticsQuery;
use app\models\ApprovalRule;
use app\models\ProjectSyncLog;
use app\models\User;
use app\services\AnalyticsService;
use yii\base\Application;
use yii\base\Controller;
use yii\web\UrlManager;
use yii\web\UrlRuleInterface;

/**
 * The analytics, approval rule, credential and project pages write values
 * into inline scripts as JSON. This release writes seven such lines with
 * Json::htmlEncode() instead of json_encode(), which leaves "<" and "!" as
 * they are: in script data, "<!--<script" puts the HTML parser into its
 * double-escaped state, where the page's own </script> no longer ends the
 * script, and with JSON_UNESCAPED_SLASHES a "</script>" in a value ends it.
 *
 * These tests render the pages with values that hold both and check what
 * reaches the browser: the value arrives as it is, hex-escaped, in a script
 * that nothing in the value ends or changes. Today none of these values can
 * hold markup (dates, role names, team ids, project statuses, URLs of routes);
 * the tests keep it so when that changes. The guard in
 * tests/unit/views/InlineScriptJsonTest.php fails on every json_encode() in a
 * view, also on the seventh line, whose value is an integer.
 */
class InlineScriptJsonRenderTest extends WebControllerTestCase
{
    use RendersRealViews;

    /** A value that ends the script, or hides its end, when written as it is. */
    private const PAYLOAD = '</script><!--<script>t-4711';

    /** PAYLOAD as Json::htmlEncode() writes it in a JSON string. */
    private const ESCAPED = '\u003C\/script\u003E\u003C!--\u003Cscript\u003Et-4711';

    public function testTheJobTrendReachesTheChartScriptEscaped(): void
    {
        $this->loginAsSuperadmin('inline-json-analytics');
        $trend = [['period' => self::PAYLOAD, 'total' => 3, 'succeeded' => 2, 'failed' => 1]];
        $ctrl = new AnalyticsController('analytics', $this->app());

        $html = $this->withJobTrend(
            $trend,
            fn (): string => $this->page($ctrl, '/analytics/index', static fn (): string => $ctrl->actionIndex())
        );

        $this->assertScriptValue($html, 'var trendData = ', ';', $trend);
        $this->assertPayloadOnlyEscaped($html);
    }

    /**
     * The view counts the approvers per role and per team, keyed by the role
     * names and team ids the controller lists. Team ids are integers; the
     * form is rendered with a team keyed by PAYLOAD, so the test shows that
     * the line escapes whatever key it gets.
     */
    public function testTheApproverCountsReachTheFormScriptEscaped(): void
    {
        $this->loginAsSuperadmin('inline-json-approval-rule');
        $model = new ApprovalRule();
        $model->required_approvals = 1;
        $model->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $model->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $params = [
            'model' => $model,
            'roles' => [self::PAYLOAD => self::PAYLOAD],
            'teams' => [self::PAYLOAD => 'e2e-team'],
            'users' => [],
        ];
        $ctrl = new ApprovalRuleController('approval-rule', $this->app());

        $html = $this->page($ctrl, '/approval-rule/create', static fn (): string => $ctrl->render('form', $params));

        $this->assertScriptValue($html, 'var roleCounts = ', ';', [self::PAYLOAD => 0]);
        $this->assertScriptValue($html, 'var teamCounts = ', ';', [self::PAYLOAD => 0]);
        $this->assertPayloadOnlyEscaped($html);
    }

    public function testTheKeyGeneratorUrlReachesTheCredentialFormScriptEscaped(): void
    {
        $this->loginAsSuperadmin('inline-json-credential');
        $this->routeTo('credential/generate-ssh-key', 'credential/generate-ssh-key/' . self::PAYLOAD);
        $ctrl = new CredentialController('credential', $this->app());

        $html = $this->page($ctrl, '/credential/create', static fn (): mixed => $ctrl->actionCreate());

        $this->assertScriptValue($html, 'fetch(', ', {', '/credential/generate-ssh-key/' . self::PAYLOAD);
        $this->assertPayloadOnlyEscaped($html);
    }

    /**
     * The sync panel, and with it the script that polls the sync, shows as
     * soon as the project has sync output.
     */
    public function testTheSyncPollingValuesReachTheProjectScriptEscaped(): void
    {
        $user = $this->loginAsSuperadmin('inline-json-project');
        $project = $this->createProject((int)$user->id);
        $project->status = self::PAYLOAD;
        $this->assertTrue($project->save(false));
        $log = new ProjectSyncLog();
        $log->project_id = (int)$project->id;
        $log->stream = 'stdout';
        $log->content = "Cloning into 'project'...\n";
        $log->sequence = 7;
        $log->created_at = time();
        $this->assertTrue($log->save(false));
        $this->routeTo('project/sync-status', 'project/sync-status/' . self::PAYLOAD);
        $ctrl = new ProjectController('project', $this->app());
        $id = (int)$project->id;

        $html = $this->page($ctrl, '/project/view?id=' . $id, static fn (): string => $ctrl->actionView($id));

        $statusUrl = '/project/sync-status/' . self::PAYLOAD . '?id=' . $id;
        $this->assertScriptValue($html, 'var statusUrl = ', ';', $statusUrl);
        $this->assertScriptValue($html, 'var initialStatus = ', ';', self::PAYLOAD);
        // An integer: both encoders write the same bytes, so only the views guard tells them apart.
        $this->assertScriptValue($html, 'var lastSeq = ', ';', 7);
        $this->assertPayloadOnlyEscaped($html);
    }

    /**
     * The directory of the views of the controller $id. A check of these
     * tests against changed copies of the views overrides it.
     */
    protected function viewPath(string $id): string
    {
        return '@app/views/' . $id;
    }

    private function app(): Application
    {
        $app = \Yii::$app;
        if ($app === null) {
            $this->fail('the test bootstrap creates the application');
        }

        return $app;
    }

    private function loginAsSuperadmin(string $suffix): User
    {
        $user = $this->createUser($suffix);
        $user->is_superadmin = true;
        $this->assertTrue($user->save(false));
        $this->loginAs($user);

        return $user;
    }

    /**
     * The page that $action renders, without the layout, as the response to
     * a request for $url.
     *
     * @param callable(): mixed $action
     */
    private function page(Controller $ctrl, string $url, callable $action): string
    {
        $ctrl->layout = false;
        $ctrl->setViewPath($this->viewPath($ctrl->id));
        $html = $this->withRealViews($ctrl, $url, $action);
        $this->assertIsString($html, 'the action renders the page');

        return $html;
    }

    /**
     * Run $render while the analytics service reports $trend as the job trend.
     *
     * @param list<array{period: string, total: int, succeeded: int, failed: int}> $trend
     * @param callable(): string $render
     */
    private function withJobTrend(array $trend, callable $render): string
    {
        $components = \Yii::$app->getComponents(true);
        $original = $components['analyticsService'] ?? null;
        \Yii::$app->set('analyticsService', $this->analyticsWithJobTrend($trend));
        try {
            return $render();
        } finally {
            \Yii::$app->set('analyticsService', $original);
        }
    }

    /**
     * The analytics service, with $trend as the job trend.
     *
     * @param list<array{period: string, total: int, succeeded: int, failed: int}> $trend
     */
    private function analyticsWithJobTrend(array $trend): AnalyticsService
    {
        return new class ($trend) extends AnalyticsService {
            /**
             * @param list<array{period: string, total: int, succeeded: int, failed: int}> $trend
             */
            public function __construct(private readonly array $trend)
            {
                parent::__construct();
            }

            public function jobTrend(AnalyticsQuery $query): array
            {
                return $this->trend;
            }
        };
    }

    /**
     * Url::to() gives "/$url" for $route, plus the query of its parameters.
     * WebControllerTestCase restores the URL manager after the test.
     */
    private function routeTo(string $route, string $url): void
    {
        \Yii::$app->set('urlManager', [
            'class' => UrlManager::class,
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
            // The rule cache keys on the serialized rules, and the rule is an anonymous class.
            'cache' => false,
            'rules' => [$this->fixedUrlRule($route, $url)],
        ]);
    }

    /**
     * A URL rule that writes $url, plus the query of the parameters, for $route.
     */
    private function fixedUrlRule(string $route, string $url): UrlRuleInterface
    {
        return new class ($route, $url) implements UrlRuleInterface {
            public function __construct(private readonly string $route, private readonly string $url)
            {
            }

            /**
             * @param UrlManager $manager
             * @param \yii\web\Request $request
             */
            public function parseRequest($manager, $request): bool
            {
                return false;
            }

            /**
             * @param UrlManager $manager
             * @param string $route
             * @param array<string, mixed> $params
             */
            public function createUrl($manager, $route, $params): string|false
            {
                if ($route !== $this->route) {
                    return false;
                }

                return $params === [] ? $this->url : $this->url . '?' . http_build_query($params);
            }
        };
    }

    /**
     * The inline script line "$prefix<JSON>$suffix": the JSON is $expected,
     * it holds no "<", ">" or "&" (Json::htmlEncode() writes \u003C, \u003E
     * and \u0026), and nothing in the script before its end ends the script
     * or hides its end from the HTML parser.
     */
    private function assertScriptValue(string $html, string $prefix, string $suffix, mixed $expected): void
    {
        $pattern = '/^[ \t]*' . preg_quote($prefix, '/') . '(.*)' . preg_quote($suffix, '/') . '$/m';
        $found = preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $found, "the page has no line {$prefix}...{$suffix}");
        [$json, $offset] = $match[1];
        $this->assertSame($expected, json_decode($json, true), 'the script gets the value as it is');
        $this->assertSame(0, preg_match('/[<>&]/', $json), "the JSON is hex-escaped: {$json}");

        $open = strrpos(substr($html, 0, $offset), '<script>');
        $this->assertNotFalse($open, 'the line is in an inline script');
        $close = stripos($html, '</script', $open);
        $this->assertNotFalse($close, 'the script ends');
        $this->assertGreaterThan($offset + strlen($json), $close, 'the script ends after the line');
        $script = substr($html, $open, $close - $open);
        $this->assertStringNotContainsString('<!--', $script, 'nothing in the script hides its end');
    }

    /**
     * The page holds PAYLOAD hex-escaped, and nowhere as it is.
     */
    private function assertPayloadOnlyEscaped(string $html): void
    {
        $this->assertStringContainsString(self::ESCAPED, $html);
        $this->assertStringNotContainsString(self::PAYLOAD, $html);
        $this->assertStringNotContainsString('<!--<script', $html);
    }
}

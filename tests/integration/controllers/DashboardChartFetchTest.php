<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\SiteController;
use yii\base\Module;

/**
 * The dashboard draws its charts from /site/chart-data, fetched when the
 * page loads and when the range changes (views/site/index.php).
 * tests/e2e/tests/site/dashboard-chart.spec.ts follows the fetch in a
 * browser.
 */
class DashboardChartFetchTest extends WebControllerTestCase
{
    /**
     * regression: the fetch asked for anything (fetch's Accept: * / *), so a
     * signed-out fetch was sent to the login page, which kept
     * /site/chart-data as the address to return to: signed in again, the
     * user landed on the chart data (JSON) instead of the dashboard. And it
     * read every answer as JSON, so a refused or failed fetch ended in an
     * error nothing caught. It asks for JSON now, and an answer that is not
     * OK, or no answer, leaves the charts as they are.
     */
    public function testRegressionTheChartFetchAskedForAnythingAndReadEveryAnswerAsJson(): void
    {
        $script = $this->chartScript($this->dashboardPage());

        $this->assertStringContainsString(
            "fetch(chartDataUrl + '?days=' + days, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })",
            $script
        );
        $this->assertStringContainsString('.then(function (r) { return r.ok ? r.json() : null; })', $script);
        $this->assertStringContainsString('if (d === null) return;', $script);
        $this->assertStringContainsString('.catch(function () { /* the charts stay as they are */ });', $script);
        $this->assertSame(1, substr_count($script, 'fetch('), 'the one fetch of the charts');
    }

    private function dashboardPage(): string
    {
        $user = $this->createUser('chart-fetch');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('site', $app) extends SiteController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;

                return 'rendered:' . $view;
            }
        };
        $ctrl->actionIndex();

        return \Yii::$app->view->renderFile('@app/views/site/index.php', $ctrl->capturedParams);
    }

    /**
     * The script that draws the charts: from its <script> tag to the first
     * "</script", which is where the browser ends it.
     */
    private function chartScript(string $html): string
    {
        $tag = strpos($html, "<script>\n(function () {\n    var chartDataUrl");
        $this->assertNotFalse($tag, 'the chart script is missing');
        $start = $tag + strlen('<script>');
        $end = stripos($html, '</script', $start);
        $this->assertNotFalse($end, 'the chart script does not end');

        return substr($html, $start, $end - $start);
    }
}

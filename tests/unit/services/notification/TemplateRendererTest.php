<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\services\notification\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testRendersSimpleVariable(): void
    {
        $result = $this->renderer->render('Hello {{ name }}!', ['name' => 'World']);
        $this->assertSame('Hello World!', $result);
    }

    public function testRendersDottedVariable(): void
    {
        $result = $this->renderer->render('Job #{{ job.id }}', ['job.id' => '42']);
        $this->assertSame('Job #42', $result);
    }

    public function testMissingVariableReplacedWithEmpty(): void
    {
        $result = $this->renderer->render('Status: {{ job.status }}', []);
        $this->assertSame('Status: ', $result);
    }

    public function testMultipleVariables(): void
    {
        $tpl = '{{ job.id }} — {{ job.status }} — {{ template.name }}';
        $vars = ['job.id' => '1', 'job.status' => 'failed', 'template.name' => 'Deploy'];
        $this->assertSame('1 — failed — Deploy', $this->renderer->render($tpl, $vars));
    }

    public function testWhitespaceInsideBraces(): void
    {
        $result = $this->renderer->render('{{  job.id  }}', ['job.id' => '99']);
        $this->assertSame('99', $result);
    }

    public function testSpecialCharactersInValues(): void
    {
        $result = $this->renderer->render('{{ msg }}', ['msg' => '<script>alert(1)</script>']);
        $this->assertSame('<script>alert(1)</script>', $result);
    }

    public function testEmptyTemplate(): void
    {
        $this->assertSame('', $this->renderer->render('', ['foo' => 'bar']));
    }

    public function testNoVariablesInTemplate(): void
    {
        $this->assertSame('plain text', $this->renderer->render('plain text', []));
    }

    public function testUnderscoreInVariableName(): void
    {
        $result = $this->renderer->render('{{ launched_by }}', ['launched_by' => 'admin']);
        $this->assertSame('admin', $result);
    }

    public function testBuildVariablesFlattensNestedPayload(): void
    {
        $vars = $this->renderer->buildVariables([
            'job' => ['id' => 42, 'status' => 'failed'],
            'template' => ['name' => 'Deploy'],
            'launched_by' => 'admin',
        ]);

        $this->assertSame('42', $vars['job.id']);
        $this->assertSame('failed', $vars['job.status']);
        $this->assertSame('Deploy', $vars['template.name']);
        $this->assertSame('admin', $vars['launched_by']);
        $this->assertArrayHasKey('timestamp', $vars);
        $this->assertArrayHasKey('app.url', $vars);
    }

    public function testBuildVariablesHandlesScalarsAndNull(): void
    {
        $vars = $this->renderer->buildVariables([
            'job' => ['id' => 1, 'exit_code' => null, 'changed' => true],
        ]);

        $this->assertSame('1', $vars['job.id']);
        $this->assertSame('', $vars['job.exit_code']);
        $this->assertSame('1', $vars['job.changed']);
    }

    public function testBuildVariablesJsonEncodesObjects(): void
    {
        $vars = $this->renderer->buildVariables([
            'ctx' => ['obj' => (object)['a' => 1]],
        ]);

        $this->assertSame('{"a":1}', $vars['ctx.obj']);
    }

    // -- baseUrl() coverage via buildVariables -----------------------------------

    public function testBaseUrlFallsBackToParam(): void
    {
        $origParams = \Yii::$app->params;
        \Yii::$app->params['appBaseUrl'] = 'https://ansilume.example.com/';

        try {
            $vars = $this->renderer->buildVariables([]);
            // rtrim strips trailing slash
            $this->assertSame('https://ansilume.example.com', $vars['app.url']);
        } finally {
            \Yii::$app->params = $origParams;
        }
    }

    public function testBaseUrlReturnsEmptyWhenNothingConfigured(): void
    {
        $origParams = \Yii::$app->params;
        unset(\Yii::$app->params['appBaseUrl']);

        try {
            $vars = $this->renderer->buildVariables([]);
            $this->assertSame('', $vars['app.url']);
        } finally {
            \Yii::$app->params = $origParams;
        }
    }

    public function testBuildVariablesIncludesTimestamp(): void
    {
        $vars = $this->renderer->buildVariables([]);
        $this->assertArrayHasKey('timestamp', $vars);
        // Timestamp should be a date-like string
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $vars['timestamp']);
    }

    public function testBuildVariablesHandlesBooleanFalse(): void
    {
        $vars = $this->renderer->buildVariables([
            'flag' => ['enabled' => false],
        ]);
        $this->assertSame('', $vars['flag.enabled']);
    }

    public function testBuildVariablesHandlesFloatValues(): void
    {
        $vars = $this->renderer->buildVariables([
            'metric' => ['duration' => 3.14],
        ]);
        $this->assertSame('3.14', $vars['metric.duration']);
    }

    public function testBuildVariablesDeeplyNestedArray(): void
    {
        $vars = $this->renderer->buildVariables([
            'a' => ['b' => ['c' => ['d' => 'deep']]],
        ]);
        $this->assertSame('deep', $vars['a.b.c.d']);
    }

    public function testRenderAndBuildVariablesEndToEnd(): void
    {
        $vars = $this->renderer->buildVariables([
            'job' => ['id' => 7, 'status' => 'failed'],
        ]);
        $result = $this->renderer->render(
            'Job #{{ job.id }} {{ job.status }} at {{ timestamp }}',
            $vars
        );
        $this->assertStringContainsString('Job #7 failed at', $result);
    }

    /**
     * Regression: `appBaseUrl` (APP_URL in the env) must outrank the current
     * request's hostInfo. When the runner finalises a job via the internal
     * nginx API the Host header is "nginx" — if hostInfo won, every
     * notification that rendered during a runner-originated request would
     * ship `http://nginx/job/view?id=…` to end users.
     */
    public function testBaseUrlPrefersAppBaseUrlOverRequestHostInfo(): void
    {
        $origParams = \Yii::$app->params;
        \Yii::$app->params['appBaseUrl'] = 'https://ansilume.example.com/';

        // Install a stub request component whose hostInfo would be preferred
        // by the old code. The new priority order must pick appBaseUrl.
        $origRequest = \Yii::$app->has('request') ? \Yii::$app->request : null;
        \Yii::$app->set('request', new class extends \yii\web\Request {
            public function getHostInfo(): string
            {
                return 'http://nginx';
            }
        });

        try {
            $vars = $this->renderer->buildVariables([]);
            $this->assertSame(
                'https://ansilume.example.com',
                $vars['app.url'],
                'baseUrl must prefer configured appBaseUrl over request->hostInfo (regression: http://nginx leaked into notifications).'
            );
        } finally {
            \Yii::$app->params = $origParams;
            if ($origRequest !== null) {
                \Yii::$app->set('request', $origRequest);
            }
        }
    }

    public function testBaseUrlFallsBackToHostInfoWhenAppBaseUrlIsEmpty(): void
    {
        $origParams = \Yii::$app->params;
        unset(\Yii::$app->params['appBaseUrl']);

        $origRequest = \Yii::$app->has('request') ? \Yii::$app->request : null;
        \Yii::$app->set('request', new class extends \yii\web\Request {
            public function getHostInfo(): string
            {
                return 'https://from-request.example';
            }
        });

        try {
            $vars = $this->renderer->buildVariables([]);
            $this->assertSame('https://from-request.example', $vars['app.url']);
        } finally {
            \Yii::$app->params = $origParams;
            if ($origRequest !== null) {
                \Yii::$app->set('request', $origRequest);
            }
        }
    }

    public function testBaseUrlEmptyWhenParamIsEmptyString(): void
    {
        $origParams = \Yii::$app->params;
        \Yii::$app->params['appBaseUrl'] = '';

        try {
            $vars = $this->renderer->buildVariables([]);
            $this->assertSame('', $vars['app.url']);
        } finally {
            \Yii::$app->params = $origParams;
        }
    }

    public function testBuildVariablesNumericKeys(): void
    {
        $vars = $this->renderer->buildVariables([
            'items' => ['0' => 'first', '1' => 'second'],
        ]);
        $this->assertSame('first', $vars['items.0']);
        $this->assertSame('second', $vars['items.1']);
    }
}

<?php

declare(strict_types=1);

namespace app\tests\integration\bin;

use app\models\NotificationTemplate;
use app\models\User;
use app\tests\integration\DbTestCase;

/**
 * Runs the notifications probe of bin/diagnose (NOTIFICATION_PROBE_PHP), the
 * PHP that diagnose runs in the app container after its boot prelude, on
 * the test database: it prints the base of the links that notifications
 * carry, whether APP_URL is set, each notification template that delivers
 * as an inactive user by the model's own rule
 * (NotificationTemplate::isDeliveringAsInactive()), in the order of their
 * IDs, and how many templates there are. tests/unit/bin/
 * DiagnoseNotificationsTest runs what diagnose makes of these lines.
 *
 * The probe runs in this process, inside the test's transaction: diagnose's
 * prelude makes the console application, which here is the test's ($app).
 *
 * Written with plan Task 14 of the workflow notifications: up to it,
 * diagnose said nothing of templates that deliver nothing.
 */
final class DiagnoseNotificationsProbeTest extends DbTestCase
{
    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, mixed> */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->params = \Yii::$app->params;
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        \Yii::$app->params = $this->params;
        $_ENV = $this->env;
        parent::tearDown();
    }

    /**
     * A template of an inactive user is listed with that user's ID, one of
     * an active user is not, and the last line counts every template.
     */
    public function testTheProbeListsTheTemplatesThatDeliverAsAnInactiveUser(): void
    {
        $active = $this->createUser('probe-active');
        $inactive = $this->createUser('probe-inactive');
        $inactive->status = User::STATUS_INACTIVE;
        $this->assertTrue($inactive->save(false));
        $first = $this->createNotificationTemplate((int)$inactive->id);
        $kept = $this->createNotificationTemplate((int)$active->id);
        $second = $this->createNotificationTemplate((int)$inactive->id);

        $lines = $this->probe();

        $inactiveLines = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'inactive|')));
        $this->assertContains('inactive|' . $first->id . '|' . $first->name . '|' . $inactive->id, $inactiveLines);
        $this->assertContains('inactive|' . $second->id . '|' . $second->name . '|' . $inactive->id, $inactiveLines);
        $this->assertSame([], array_values(array_filter($inactiveLines, static fn (string $line): bool => str_starts_with($line, 'inactive|' . $kept->id . '|'))));
        $ids = array_map(static fn (string $line): int => (int)explode('|', $line)[1], $inactiveLines);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'In the order of their IDs.');
        $this->assertSame('templates|' . NotificationTemplate::find()->count(), $lines[count($lines) - 1]);
        $this->assertStringStartsWith('app_url|', $lines[0]);
    }

    /**
     * The first line says whether APP_URL is set and gives the base of the
     * links without the user of a URL, which the output of diagnose is
     * shared.
     */
    public function testTheFirstLineSaysWhetherAppUrlIsSetAndLeavesOutAUser(): void
    {
        \Yii::$app->params['appBaseUrl'] = 'https://ops:s3cr3t@ansilume.example.com/ansilume';
        $_ENV['APP_URL'] = 'https://ops:s3cr3t@ansilume.example.com/ansilume/';
        $this->assertSame('app_url|1|https://ansilume.example.com/ansilume', $this->probe()[0]);

        unset($_ENV['APP_URL']);
        \Yii::$app->params['appBaseUrl'] = 'http://localhost:8080';
        $this->assertSame('app_url|0|http://localhost:8080', $this->probe()[0]);
    }

    /**
     * Regression guard: a name with a line break or "|" cannot make lines
     * that pass for another template, or move the user's ID.
     */
    public function testANameCannotBreakTheLines(): void
    {
        $inactive = $this->createUser('probe-name');
        $inactive->status = User::STATUS_INACTIVE;
        $this->assertTrue($inactive->save(false));
        $template = $this->createNotificationTemplate((int)$inactive->id);
        $template->name = "evil\ninactive|1|fake|1";
        $this->assertTrue($template->save(false));

        $this->assertContains('inactive|' . $template->id . '|evil inactive 1 fake 1|' . $inactive->id, $this->probe());
    }

    /**
     * The lines the probe prints, as diagnose's prelude would leave it: the
     * console application in $app.
     *
     * @return list<string>
     */
    private function probe(): array
    {
        $code = $this->probeCode();
        $file = (string)tempnam(sys_get_temp_dir(), 'ansilume-diagnose-probe');
        file_put_contents($file, "<?php\n" . $code);
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Application::class, $app);
        ob_start();
        try {
            (static function (string $file, \yii\base\Application $app): void {
                require $file;
            })($file, $app);
        } finally {
            $output = (string)ob_get_clean();
            unlink($file);
        }

        return explode("\n", rtrim($output, "\n"));
    }

    /**
     * The PHP of NOTIFICATION_PROBE_PHP in bin/diagnose, a shell string in
     * single quotes, so that it is the probe as diagnose runs it.
     */
    private function probeCode(): string
    {
        $diagnose = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/diagnose');
        if (preg_match("/^NOTIFICATION_PROBE_PHP='(.*?)'$/ms", $diagnose, $match) !== 1) {
            self::fail('bin/diagnose has no NOTIFICATION_PROBE_PHP.');
        }
        $this->assertStringNotContainsString("'", $match[1], 'The probe holds no single quote, which would end the shell string.');

        return $match[1];
    }
}

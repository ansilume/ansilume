<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use yii\base\Application;
use yii\log\FileTarget;

/**
 * The tests' application logs to runtime/logs/test.log, never to app.log.
 *
 * Regression: the target of config/test.php wrote runtime/logs/app.log, the
 * file of the web UI and the console. The tests run as root in the app
 * container, so in a git checkout, whose runtime/ the containers share, a
 * test run created that file as root when it was missing and rotated it when
 * it was full, leaving app.log.N files that root owns. php-fpm's workers run
 * as www-data and cannot append to a file that root created
 * (tests/unit/docker/EntrypointLogOwnershipTest.php).
 */
class TestApplicationLogFileTest extends TestCase
{
    public function testTheTestsLogToAFileOfTheirOwn(): void
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        $logs = $app->getRuntimePath() . '/logs';

        $files = [];
        foreach ($app->getLog()->targets as $name => $target) {
            $this->assertInstanceOf(FileTarget::class, $target, "log target {$name}");
            $files[] = (string)$target->logFile;
        }

        $this->assertSame([$logs . '/test.log'], $files);
    }
}

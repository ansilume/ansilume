<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * config/web.php, config/console.php and config/test.php load without a PHP
 * error, whatever optional variable the environment leaves out.
 *
 * Regression: the mailer of config/web.php and config/console.php read
 * $_ENV['SMTP_USER'] and $_ENV['SMTP_PASSWORD'] without a default. With
 * SMTP_HOST set and the two left out, as for a relay that takes mail without
 * a password (docs/installation.md calls both optional), PHP warned
 * 'Undefined array key "SMTP_USER"' and 'Undefined array key
 * "SMTP_PASSWORD"' on every web request and every console run. The configs
 * load before Yii's error handler exists, so the warnings went to PHP's error
 * log; code that loads a config under that handler, as a test does, threw.
 *
 * A fresh PHP process (PROBE) loads each config as the entry scripts do, but
 * without .env: its environment and $_ENV hold the variables of the case and
 * nothing else. Its error handler records every error, deprecations
 * included.
 */
class OptionalVariablesConfigTest extends TestCase
{
    /**
     * Loads config/web.php, config/console.php and config/test.php (the
     * repository root is the first argument) with $_ENV set to the JSON
     * object of the second, and prints the errors raised while they load and
     * the mailer of each as JSON: only the mailers, the rest of a config
     * holds the cookie validation key.
     */
    private const PROBE = <<<'PHP'
        <?php

        declare(strict_types=1);

        define('YII_ENV', 'prod');
        define('YII_DEBUG', false);
        $root = $argv[1];
        require $root . '/vendor/autoload.php';
        require $root . '/vendor/yiisoft/yii2/Yii.php';
        // As phpdotenv and variables_order hand .env and the environment to
        // the entry scripts. The environment of this process cannot carry an
        // empty value: proc_open() leaves it out.
        $_ENV = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        $errors = [];
        set_error_handler(
            static function (int $level, string $message, string $file, int $line) use (&$errors): bool {
                $errors[] = basename($file) . ':' . $line . ': ' . $message;

                return true;
            }
        );
        $mailers = [];
        foreach (['web', 'console', 'test'] as $name) {
            $config = require $root . '/config/' . $name . '.php';
            $mailers[$name] = $config['components']['mailer'] ?? null;
        }
        restore_error_handler();
        echo json_encode(['errors' => $errors, 'mailers' => $mailers], JSON_THROW_ON_ERROR);

        PHP;

    /** The relay of the cases: a reserved name that resolves nowhere. */
    private const RELAY = 'relay.example.invalid';

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/optional-variables-config-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * The variables of each case, and the SMTP transport that config/web.php
     * and config/console.php give them; [] is no transport, mail goes to
     * files.
     *
     * @return array<string, array{0: array<string, string>, 1: array<string, int|string>}>
     */
    public static function environmentProvider(): array
    {
        $relay = ['scheme' => 'smtp', 'host' => self::RELAY, 'port' => 587];

        return [
            'no variable at all' => [[], []],
            'regression: SMTP_HOST without SMTP_USER and SMTP_PASSWORD, a relay without a password' => [
                ['SMTP_HOST' => self::RELAY],
                $relay,
            ],
            'SMTP_USER and SMTP_PASSWORD empty, as in .env.example' => [
                ['SMTP_HOST' => self::RELAY, 'SMTP_USER' => '', 'SMTP_PASSWORD' => ''],
                $relay,
            ],
            'a user and a password over SSL' => [
                [
                    'SMTP_HOST' => self::RELAY,
                    'SMTP_PORT' => '465',
                    'SMTP_ENCRYPTION' => 'ssl',
                    'SMTP_USER' => 'mailer',
                    'SMTP_PASSWORD' => 'fixture-password',
                ],
                ['scheme' => 'smtps', 'host' => self::RELAY, 'port' => 465, 'username' => 'mailer', 'password' => 'fixture-password'],
            ],
        ];
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, int|string> $transport
     */
    #[DataProvider('environmentProvider')]
    public function testTheConfigsLoadWithoutAPhpError(array $variables, array $transport): void
    {
        $result = $this->load($variables);

        $this->assertSame([], $result['errors'], 'PHP errors while the configs loaded');
        foreach (['web', 'console'] as $name) {
            $mailer = $result['mailers'][$name] ?? null;
            $this->assertIsArray($mailer, "config/{$name}.php has a mailer");
            $this->assertSame($transport, $mailer['transport'] ?? null, "the SMTP transport of config/{$name}.php");
            $this->assertSame($transport === [], $mailer['useFileTransport'] ?? null, "config/{$name}.php writes mail to files without SMTP_HOST");
        }
        $this->assertSame(true, $result['mailers']['test']['useFileTransport'] ?? null, 'the tests never send mail');
    }

    /**
     * What PROBE prints for $variables, from a PHP process that reports every
     * error and whose environment holds $variables only.
     *
     * @param array<string, string> $variables
     * @return array{errors: list<string>, mailers: array<string, array<string, mixed>|null>}
     */
    private function load(array $variables): array
    {
        $root = dirname(__DIR__, 3);
        $probe = $this->directory . '/probe.php';
        file_put_contents($probe, self::PROBE);
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'error_reporting=-1',
                '-d', 'display_errors=stderr',
                '-d', 'log_errors=0',
                $probe,
                $root,
                json_encode((object)$variables, JSON_THROW_ON_ERROR),
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $variables
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "The configs did not load: {$stderr}");
        $result = json_decode($stdout, true);
        $this->assertIsArray($result, "The probe printed no JSON: {$stdout}{$stderr}");
        $this->assertIsArray($result['errors'] ?? null);
        $this->assertIsArray($result['mailers'] ?? null);

        /** @var array{errors: list<string>, mailers: array<string, array<string, mixed>|null>} $result */
        return $result;
    }
}

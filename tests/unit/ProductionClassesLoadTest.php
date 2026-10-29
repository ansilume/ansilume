<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Every class of the production packages loads on the running PHP without
 * an error that the error handler would turn into an exception.
 *
 * Regression (PHP 8.4 and 8.5): yii2-redis 2.0.20's SocketException
 * declared `\Exception $previous = null`, an implicitly nullable parameter,
 * which PHP 8.4 deprecates when it compiles the class. Yii's error handler
 * throws for every error that is reported, deprecations included, so the
 * autoloader threw a yii\base\ErrorException
 * ("yii\redis\SocketException::__construct(): Implicitly marking parameter
 * $previous as nullable is deprecated, ...") instead of declaring the class:
 * every Redis socket error of a queue worker, a schedule runner or a runner
 * turned into that message. A test that loads the class failed only when it
 * was the first in its process to load it.
 *
 * So a fresh PHP process (LOADER) requires vendor/autoload.php and Yii.php,
 * as the entry scripts do, and loads every class that the optimized
 * classmap (vendor/composer/autoload_classmap.php, which composer install
 * --optimize-autoloader writes in every image and in the dev entrypoint)
 * maps into a package that vendor/composer/installed.php lists as no dev
 * requirement. Its error handler records what Yii's would throw: every
 * error that error_reporting() does not suppress. Symfony announces
 * deprecations with @trigger_error, which suppresses them, and Yii ignores
 * them as well.
 *
 * A class that cannot load (it extends a class of a package that is not
 * installed, such as Symfony Mailer's MessageDataCollector, which needs
 * symfony/http-kernel) cannot load in production either, so it is no
 * finding. CLASSES_THAT_CANNOT_LOAD pins their number, so that a change
 * names them.
 */
class ProductionClassesLoadTest extends TestCase
{
    /**
     * The production classes that cannot load, all for a package that
     * Ansilume does not install: Symfony Mailer's MessageDataCollector
     * (symfony/http-kernel), the six test classes of cebe/markdown (PHPUnit
     * 4) and the two Composer plugin classes of yiisoft/yii2-composer
     * (composer/composer, which runs them as a program of its own).
     */
    private const CLASSES_THAT_CANNOT_LOAD = 9;

    /**
     * The child process. Its argument is a JSON file with the files to
     * require first ("bootstrap") and the classes to load ("classes"); it
     * prints JSON: how many classes loaded, the ones that could not load with
     * the reason, and each error the handler recorded, with the class whose
     * load raised it ("bootstrap" while it requires the bootstrap files).
     */
    private const LOADER = <<<'PHP'
        <?php

        declare(strict_types=1);

        $input = json_decode((string)file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $loading = 'bootstrap';
        $errors = [];
        set_error_handler(
            static function (int $level, string $message, string $file, int $line) use (&$loading, &$errors): bool {
                if ((error_reporting() & $level) !== 0) {
                    $errors[] = ['class' => $loading, 'level' => $level, 'message' => $message]
                        + ['file' => $file, 'line' => $line];
                }

                return true;
            }
        );
        $finished = false;
        register_shutdown_function(static function () use (&$loading, &$finished): void {
            if (!$finished) {
                fwrite(STDERR, "The process ended while it loaded {$loading}.\n");
            }
        });
        foreach ($input['bootstrap'] as $file) {
            require $file;
        }
        $loaded = 0;
        $failed = [];
        foreach ($input['classes'] as $class) {
            $loading = $class;
            try {
                if (class_exists($class) || interface_exists($class, false) || trait_exists($class, false)) {
                    $loaded++;
                } else {
                    $failed[$class] = 'its file declares no such class';
                }
            } catch (\Throwable $e) {
                $failed[$class] = get_class($e) . ': ' . $e->getMessage();
            }
        }
        $finished = true;
        echo json_encode(
            ['loaded' => $loaded, 'failed' => $failed, 'errors' => $errors],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );

        PHP;

    /**
     * The classes of testTheLoaderReportsWhatTheErrorHandlerWouldThrow, by
     * file, and the bootstrap that loads them.
     */
    private const FIXTURES = [
        'autoload.php' => <<<'PHP'
            <?php

            declare(strict_types=1);

            spl_autoload_register(static function (string $class): void {
                $file = __DIR__ . '/' . substr((string)strrchr($class, '\\'), 1) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            });

            PHP,
        'Clean.php' => <<<'PHP'
            <?php

            namespace ProductionClassesLoadFixture;

            class Clean
            {
            }

            PHP,
        // The constructor of yii2-redis 2.0.20's SocketException.
        'ImplicitlyNullable.php' => <<<'PHP'
            <?php

            namespace ProductionClassesLoadFixture;

            class ImplicitlyNullable extends \Exception
            {
                public function __construct($message = null, $code = 0, \Exception $previous = null)
                {
                    parent::__construct((string)$message, (int)$code, $previous);
                }
            }

            PHP,
        'MissingParent.php' => <<<'PHP'
            <?php

            namespace ProductionClassesLoadFixture;

            class MissingParent extends NotInstalled
            {
            }

            PHP,
        'NotDeclared.php' => <<<'PHP'
            <?php

            namespace ProductionClassesLoadFixture;

            class SomethingElse
            {
            }

            PHP,
        // As Symfony announces a deprecation (trigger_deprecation()).
        'SuppressedDeprecation.php' => <<<'PHP'
            <?php

            namespace ProductionClassesLoadFixture;

            @\trigger_error('Since vendor/package 1.2: The class is deprecated.', E_USER_DEPRECATED);

            class SuppressedDeprecation
            {
            }

            PHP,
        'UserDeprecation.php' => <<<'PHP'
            <?php

            namespace ProductionClassesLoadFixture;

            \trigger_error('The class is deprecated.', E_USER_DEPRECATED);

            class UserDeprecation
            {
            }

            PHP,
        'UserWarning.php' => <<<'PHP'
            <?php

            namespace ProductionClassesLoadFixture;

            \trigger_error('The class warns.', E_USER_WARNING);

            class UserWarning
            {
            }

            PHP,
    ];

    /** The names of the error levels in a finding. */
    private const LEVELS = [
        E_DEPRECATED => 'E_DEPRECATED',
        E_NOTICE => 'E_NOTICE',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_USER_WARNING => 'E_USER_WARNING',
        E_WARNING => 'E_WARNING',
    ];

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/production_classes_load_' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testEveryProductionClassLoadsWithoutAnError(): void
    {
        $root = self::root();
        $classes = self::productionClasses();

        $result = $this->load([$root . '/vendor/autoload.php', $root . '/vendor/yiisoft/yii2/Yii.php'], $classes);

        $this->assertSame(
            [],
            self::findingsOf($result, $root),
            'These production classes raise an error while they load, which the error handler turns into an'
            . ' exception that the autoloader throws instead of declaring the class: update the package to a'
            . ' release that fixes it'
        );
        $this->assertSame(count($classes), $result['loaded'] + count($result['failed']), 'Each class loads or fails.');
        $this->assertSame(
            self::CLASSES_THAT_CANNOT_LOAD,
            count($result['failed']),
            "The number of production classes that cannot load changed, and this test checks only those that load.\n"
            . 'Where each misses a package that Ansilume does not install, set CLASSES_THAT_CANNOT_LOAD:' . "\n"
            . self::listed($result['failed'])
        );
    }

    /**
     * The classes come from the optimized classmap and are those of the
     * production packages: without --optimize-autoloader the classmap holds
     * the few classes of packages that autoload by classmap only, and this
     * test would load nearly nothing.
     */
    public function testTheClassesAreThoseOfTheProductionPackages(): void
    {
        $classes = self::productionClasses();

        $this->assertContains('yii\redis\SocketException', $classes, 'The classmap is not optimized.');
        $this->assertContains('yii\base\Component', $classes);
        $this->assertContains('Symfony\Component\Mime\Email', $classes, 'A dependency of a production package too.');
        $this->assertContains('Symfony\Component\Mailer\DataCollector\MessageDataCollector', $classes);
        $this->assertNotContains('PHPUnit\Framework\TestCase', $classes, 'phpunit/phpunit is a dev requirement.');
        $this->assertNotContains('yii\debug\Module', $classes, 'yiisoft/yii2-debug is a dev requirement.');
        $this->assertSame(
            [],
            array_values(array_filter($classes, static fn (string $class): bool => str_starts_with($class, 'app\\'))),
            'Ansilume itself is the root package, not a dependency.'
        );
    }

    /**
     * The loader and the findings, on classes made for it: an implicitly
     * nullable parameter as in yii2-redis 2.0.20, a deprecation and a
     * warning that a class file raises, Symfony's suppressed deprecation, and
     * the two ways a class does not load.
     */
    public function testTheLoaderReportsWhatTheErrorHandlerWouldThrow(): void
    {
        foreach (self::FIXTURES as $file => $code) {
            file_put_contents($this->directory . '/' . $file, $code);
        }
        $classes = array_map(
            static fn (string $file): string => 'ProductionClassesLoadFixture\\' . basename($file, '.php'),
            array_values(array_diff(array_keys(self::FIXTURES), ['autoload.php']))
        );

        $result = $this->load([$this->directory . '/autoload.php'], $classes);

        $this->assertSame([
            'ProductionClassesLoadFixture\ImplicitlyNullable: E_DEPRECATED'
                . ' ProductionClassesLoadFixture\ImplicitlyNullable::__construct(): Implicitly marking parameter'
                . ' $previous as nullable is deprecated, the explicit nullable type must be used instead'
                . ' (ImplicitlyNullable.php:7)',
            'ProductionClassesLoadFixture\UserDeprecation: E_USER_DEPRECATED The class is deprecated.'
                . ' (UserDeprecation.php:5)',
            'ProductionClassesLoadFixture\UserWarning: E_USER_WARNING The class warns. (UserWarning.php:5)',
        ], self::findingsOf($result, $this->directory));
        $this->assertSame(5, $result['loaded']);
        $this->assertSame([
            'ProductionClassesLoadFixture\MissingParent' => 'Error: Class "ProductionClassesLoadFixture\NotInstalled"'
                . ' not found',
            'ProductionClassesLoadFixture\NotDeclared' => 'its file declares no such class',
        ], $result['failed']);
    }

    /**
     * What LOADER reports for $classes in a fresh PHP process that shows
     * every error and has no OPcache, so that every class compiles.
     *
     * @param list<string> $bootstrap
     * @param list<string> $classes
     * @return array{loaded: int, failed: array<string, string>, errors: list<array<string, int|string>>}
     */
    private function load(array $bootstrap, array $classes): array
    {
        $loader = $this->directory . '/loader.php';
        $input = $this->directory . '/input.json';
        file_put_contents($loader, self::LOADER);
        file_put_contents($input, json_encode(['bootstrap' => $bootstrap, 'classes' => $classes], JSON_THROW_ON_ERROR));
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'error_reporting=-1',
                '-d', 'display_errors=stderr',
                '-d', 'log_errors=0',
                '-d', 'opcache.enable_cli=0',
                $loader,
                $input,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root()
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "The loader failed: {$stderr}");
        $result = json_decode($stdout, true);
        $this->assertIsArray($result, "The loader printed no JSON: {$stdout}{$stderr}");
        $this->assertIsInt($result['loaded'] ?? null);
        $this->assertIsArray($result['failed'] ?? null);
        $this->assertIsArray($result['errors'] ?? null);

        /** @var array{loaded: int, failed: array<string, string>, errors: list<array<string, int|string>>} $result */
        return $result;
    }

    /**
     * The classes of the production packages: those that the classmap maps
     * into the install path of a package that installed.php lists as no dev
     * requirement, but the root package.
     *
     * @return list<string>
     */
    private static function productionClasses(): array
    {
        $root = self::root();
        $installed = require $root . '/vendor/composer/installed.php';
        $paths = [];
        foreach ($installed['versions'] as $name => $package) {
            $production = ($package['dev_requirement'] ?? true) === false && isset($package['install_path']);
            if (!$production || $name === $installed['root']['name']) {
                continue;
            }
            $path = realpath((string)$package['install_path']);
            if ($path !== false) {
                $paths[] = $path . '/';
            }
        }
        $classes = [];
        foreach (require $root . '/vendor/composer/autoload_classmap.php' as $class => $file) {
            $real = (string)(realpath((string)$file) ?: $file);
            foreach ($paths as $path) {
                if (str_starts_with($real, $path)) {
                    $classes[] = (string)$class;
                    break;
                }
            }
        }
        sort($classes);

        return $classes;
    }

    /**
     * Each recorded error as "<class>: <level> <message> (<file>:<line>)",
     * the file relative to $root.
     *
     * @param array{errors: list<array<string, int|string>>} $result each error's class, level, message,
     *     file and line
     * @return list<string>
     */
    private static function findingsOf(array $result, string $root): array
    {
        $findings = [];
        foreach ($result['errors'] as $error) {
            $file = (string)$error['file'];
            $file = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
            $level = self::LEVELS[$error['level']] ?? "error level {$error['level']}";
            $findings[] = "{$error['class']}: {$level} {$error['message']} ({$file}:{$error['line']})";
        }

        return $findings;
    }

    /**
     * @param array<string, string> $failed
     */
    private static function listed(array $failed): string
    {
        $lines = [];
        foreach ($failed as $class => $reason) {
            $lines[] = "{$class}: {$reason}";
        }

        return implode("\n", $lines);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

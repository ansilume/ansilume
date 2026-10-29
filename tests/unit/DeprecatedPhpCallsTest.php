<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Regression (PHP 8.5): the error handler of the application turns every
 * deprecation into a yii\base\ErrorException, in the web app, the console,
 * the queue worker, the runners and the tests alike, so a deprecated call
 * fails whatever it is part of.
 *
 * curl_close() is deprecated since 8.5: every Slack, Teams, Telegram,
 * PagerDuty and webhook notification was delivered and then recorded as
 * failed. ReflectionProperty::setAccessible() and
 * ReflectionMethod::setAccessible() are deprecated since 8.5: 82 calls in
 * tests made 405 tests fail. $http_response_header is deprecated since 8.5,
 * at compile time: a plain read of it fails its class while it loads, and
 * RunnerHttpClient, which read it, is how a runner talks to the server.
 *
 * So no list names what a PHP line deprecates: PhpDeprecations reads it from
 * the running PHP by reflection, and DeprecatedPhpUseScan finds its uses in
 * every PHP file of the repository (DeprecatedPhpUseScan::sourceFilesIn():
 * the tests and the yii script too; not vendor/, runtime/, docker/,
 * web/assets/ or node_modules/). On a new PHP line, each use of what it
 * deprecates fails here at once, before a test or a request reaches it, with
 * what PHP says, which mostly names what to use instead.
 *
 * A method call or a class constant is known by its name only, as the scan
 * cannot tell the class of an object. So a name counts when every internal
 * class that declares it deprecates it, and when no userland code (the
 * repository's, and every file of the dependencies' classmap) declares a
 * method or constant of that name or documents a magic method of that name
 * (DeclaredNames): SplObjectStorage::attach() is deprecated,
 * SplSubject::attach() is not; Redis::delete() of phpredis is,
 * Phar::delete() is not, and ActiveRecord::delete() is userland.
 *
 * What the scan cannot see, and the suites find only where they reach it:
 * - the deprecated names that userland declares too (USERLAND_METHODS,
 *   USERLAND_CLASS_CONSTANTS): `->isArray(` may be symfony/console's
 *   InputArgument::isArray(), so ReflectionParameter::isArray() goes
 *   unchecked;
 * - a call the code does not spell out: a callable string or array
 *   ('curl_close', [$property, 'setAccessible']), a variable function or
 *   method ($f(), $object->$name()), an alias of `use function`, a magic
 *   method that no @method documents;
 * - what PHP deprecates for some arguments or values only: CSV functions
 *   without $escape (CsvTextTest), null as an array offset; what it
 *   deprecates when it compiles a file, such as an implicitly nullable
 *   parameter (the lint suite's php -l); and the deprecations of the
 *   dependencies (ProductionClassesLoadTest).
 * It takes for a use what is none in one case: a function or constant that
 * a namespace declares under the name of a deprecated one, used unqualified
 * in that namespace.
 */
class DeprecatedPhpCallsTest extends TestCase
{
    /**
     * The deprecated method names that userland declares too, so their calls
     * are not checked: ReflectionParameter::getClass(), isArray() and
     * isCallable() (deprecated since 8.0) and phpredis' Redis::slaveof(),
     * which yii2-redis and predis document as magic methods.
     */
    private const USERLAND_METHODS = ['getclass', 'isarray', 'iscallable', 'slaveof'];

    /** The same for the deprecated class constant names. */
    private const USERLAND_CLASS_CONSTANTS = [];

    /**
     * The scan of the repository, once for all tests (repository()).
     *
     * @var array{
     *     uses: array<string, list<string>>,
     *     declarations: array{methods: array<string, list<string>>, classConstants: array<string, list<string>>}
     * }|null
     */
    private static ?array $repository = null;

    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            FileHelper::removeDirectory($this->directory);
        }
    }

    public function testNoCodeCallsAFunctionThatPhpDeprecates(): void
    {
        $this->assertSame(
            [],
            self::repository()['uses'][DeprecatedPhpUseScan::FUNCTION] ?? [],
            'The running PHP deprecates these functions, and the error handler turns that into an exception: call'
            . ' what the message names instead; a handle that curl_close() and the like freed is freed once'
            . ' nothing refers to it'
        );
    }

    public function testNoCodeCallsAMethodThatPhpDeprecates(): void
    {
        $this->assertSame(
            [],
            self::repository()['uses'][DeprecatedPhpUseScan::METHOD] ?? [],
            'The running PHP deprecates these methods, and the error handler turns that into an exception: call'
            . ' what the message names instead; setAccessible() has no effect since PHP 8.1, leave it out'
        );
    }

    public function testNoCodeUsesAConstantThatPhpDeprecates(): void
    {
        $this->assertSame(
            [],
            self::repository()['uses'][DeprecatedPhpUseScan::CONSTANT] ?? [],
            'The running PHP deprecates these constants, and the error handler turns that into an exception: use'
            . ' what the message names instead'
        );
    }

    public function testNoCodeReadsHttpResponseHeader(): void
    {
        $this->assertSame(
            [],
            self::repository()['uses'][DeprecatedPhpUseScan::HTTP_RESPONSE_HEADER] ?? [],
            '$http_response_header is deprecated since PHP 8.5: call http_clear_last_response_headers() before'
            . ' the request, http_get_last_response_headers() after it'
        );
    }

    /**
     * USERLAND_METHODS and USERLAND_CLASS_CONSTANTS name what the scan leaves
     * unchecked. A name that comes or goes (a deprecation of a new PHP line,
     * a declaration in a dependency) fails until the list says so.
     */
    public function testTheDeprecatedNamesThatUserlandDeclaresTooAreKnown(): void
    {
        $declarations = self::repository()['declarations'];
        $explain = static function (array $names): string {
            $lines = [];
            foreach ($names as $name => $paths) {
                $lines[] = "{$name}: " . implode(', ', array_slice($paths, 0, 3)) . (count($paths) > 3 ? ', ...' : '');
            }

            return 'The running PHP deprecates these names and userland declares them too, so the scan cannot check'
                . " their uses: check them by hand, then list them here.\n" . implode("\n", $lines);
        };

        $this->assertSame(
            self::USERLAND_METHODS,
            array_keys($declarations['methods']),
            $explain($declarations['methods'])
        );
        $this->assertSame(
            self::USERLAND_CLASS_CONSTANTS,
            array_keys($declarations['classConstants']),
            $explain($declarations['classConstants'])
        );
    }

    /**
     * The scan finds each kind of use, however it is spelled, and only
     * those, here with deprecations of its own.
     */
    public function testTheScanFindsEachKindOfUse(): void
    {
        $uses = self::linesByKind(new DeprecatedPhpUseScan(self::madeUpDeprecations()), <<<'PHP'
            <?php
            curl_close($a);
            \curl_close($b);
            CURL_CLOSE ($c);
            $free = xml_parser_free(...);
            $property->setAccessible(true);
            $method?->SetAccessible(true);
            parent::setAccessible(true);
            $level = E_STRICT;
            $mask = E_ALL & ~\E_STRICT;
            $options = [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'];
            $command = \PDO::MYSQL_ATTR_INIT_COMMAND;
            switch ($level) { case E_STRICT: break; }
            $text = $strict ? E_STRICT : 0;
            $status = $http_response_header[0] ?? '';
            $line = "{$http_response_header[0]}";
            $line = "status: $http_response_header[0]";
            PHP);

        $this->assertSame([
            DeprecatedPhpUseScan::FUNCTION => [2, 3, 4, 5],
            DeprecatedPhpUseScan::METHOD => [6, 7, 8],
            DeprecatedPhpUseScan::CONSTANT => [9, 10, 11, 12, 13, 14],
            DeprecatedPhpUseScan::HTTP_RESPONSE_HEADER => [15, 16, 17],
        ], $uses);
    }

    /**
     * Names that are no use of the deprecated function, method or constant:
     * members, declarations, classes, other namespaces, strings, comments,
     * named arguments and labels.
     */
    public function testTheScanFindsNothingElse(): void
    {
        $uses = self::linesByKind(new DeprecatedPhpUseScan(self::madeUpDeprecations()), <<<'PHP'
            <?php
            namespace Tools;

            use const Other\E_STRICT;

            $client->curl_close($a);
            $client?->curl_close($b);
            Handles::curl_close($c);
            function curl_close($d) {}
            function &xml_parser_free($e) {}
            $object = new curl_close();
            #[curl_close(1)]
            class Lists {}
            Other\curl_close($f);
            namespace\curl_close($g);
            $text = 'curl_close($h) $r->setAccessible(true) E_STRICT $http_response_header';
            // curl_close($i); E_STRICT
            /** $property->setAccessible(true); PDO::MYSQL_ATTR_INIT_COMMAND */
            $accessible = $property->setAccessible;
            $accessible = setAccessible(true);
            $headers = $this->http_response_header;
            $label = "$levels[E_STRICT] {$levels['E_STRICT']} $object->setAccessible(true)";
            $count = Levels::E_STRICT + Other\E_STRICT + namespace\E_STRICT;
            class Levels { const E_STRICT = 1; const int MYSQL_ATTR_INIT_COMMAND = 2, MORE = 3; }
            enum Level { case E_STRICT; case MYSQL_ATTR_INIT_COMMAND; }
            report(E_STRICT: 1);
            E_STRICT: $level = 1;
            goto E_STRICT;
            try {} catch (E_STRICT $e) {}
            $is = $e instanceof E_STRICT;
            PHP);

        $this->assertSame([], $uses);
    }

    /**
     * A deprecated method or class constant name that userland declares as
     * well is not checked: a call of the userland method is no use.
     */
    public function testANameThatUserlandDeclaresTooIsNotChecked(): void
    {
        $deprecations = new PhpDeprecations(
            [],
            ['delete' => 'D', 'reset' => 'R', 'setaccessible' => 'S', 'slaveof' => 'L', 'sort' => 'O'],
            [],
            ['FL_RECOMPRESS' => 'F', 'MYSQL_ATTR_INIT_COMMAND' => 'M', 'RFC7231' => 'C', 'SECOND' => 'E']
        );
        $declarations = $deprecations->userlandDeclarations([
            'vendor/yiisoft/yii2/db/BaseActiveRecord.php' => '<?php class BaseActiveRecord {'
                . ' public function Delete() {} }',
            'vendor/a/b/Sorter.php' => '<?php interface Sorter { public static function &sort(array $list): array; }',
            'vendor/yiisoft/yii2-redis/src/ConnectionInterface.php' => "<?php\n/**\n"
                . " * @method mixed slaveof(\$host, \$port) Make the server a replica\n */\n"
                . 'interface ConnectionInterface {}',
            'models/Report.php' => '<?php class Report { const string RFC7231 = "D, d M Y";'
                . ' const A = 1, SECOND = 2; }',
            'models/Zip.php' => '<?php enum Zip: int { case FL_RECOMPRESS = 16; }',
        ]);

        $this->assertSame([
            'methods' => [
                'delete' => ['vendor/yiisoft/yii2/db/BaseActiveRecord.php'],
                'slaveof' => ['vendor/yiisoft/yii2-redis/src/ConnectionInterface.php'],
                'sort' => ['vendor/a/b/Sorter.php'],
            ],
            'classConstants' => [
                'FL_RECOMPRESS' => ['models/Zip.php'],
                'RFC7231' => ['models/Report.php'],
                'SECOND' => ['models/Report.php'],
            ],
        ], $declarations);
        $uses = self::linesByKind(new DeprecatedPhpUseScan($deprecations->without($declarations)), <<<'PHP'
            <?php
            $model->delete();
            $redis->slaveof('primary', 6379);
            Sorter::sort($list);
            $format = Report::RFC7231 . Zip::FL_RECOMPRESS->value . Report::SECOND;
            $property->setAccessible(true);
            $form->reset();
            $options = [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'];
            PHP);

        $this->assertSame([DeprecatedPhpUseScan::METHOD => [6, 7], DeprecatedPhpUseScan::CONSTANT => [8]], $uses);

        $activeRecord = (string)file_get_contents(dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/db/BaseActiveRecord.php');
        $this->assertSame(
            ['methods' => ['delete'], 'classConstants' => []],
            (new DeclaredNames(['delete'], []))->in($activeRecord),
            'Yii declares ActiveRecord::delete(), the name of phpredis\' deprecated Redis::delete().'
        );
    }

    /**
     * The deprecations of the running PHP, as the repository's scan checks
     * them: what PHP says for each kind, and a name that one internal class
     * deprecates and another does not, or that userland declares too, is no
     * deprecated name.
     */
    public function testTheScanChecksWhatTheRunningPhpDeprecates(): void
    {
        $code = <<<'PHP'
            <?php
            curl_close($handle);
            $property->setAccessible(true);
            $level = E_ALL & ~E_STRICT;
            $format = DATE_RFC7231 . \DateTimeInterface::RFC7231;
            $options = [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'];
            $status = $http_response_header[0];
            $model->delete();
            $storage->attach($object);
            $redis->slaveof('primary', 6379);
            $type = $parameter->getClass();
            PHP;
        $found = [];
        foreach ((new DeprecatedPhpUseScan(self::checkedDeprecations()))->usesIn($code) as $use) {
            $found[] = $use['line'] . ': ' . $use['says'];
        }

        $this->assertSame([
            '2: Function curl_close() is deprecated since 8.5, as it has no effect since PHP 8.0',
            '3: Method ReflectionMethod::setAccessible() is deprecated since 8.5, as it has no effect since PHP 8.1;'
                . ' Method ReflectionProperty::setAccessible() is deprecated since 8.5, as it has no effect since'
                . ' PHP 8.1',
            '4: Constant E_STRICT is deprecated since 8.4, the error level was removed',
            '5: Constant DATE_RFC7231 is deprecated since 8.5, as this format ignores the associated timezone and'
                . ' always uses GMT',
            '5: Constant DateTimeInterface::RFC7231 is deprecated since 8.5, as this format ignores the associated'
                . ' timezone and always uses GMT',
            '6: Constant PDO::MYSQL_ATTR_INIT_COMMAND is deprecated since 8.5, use Pdo\Mysql::ATTR_INIT_COMMAND'
                . ' instead',
            '7: ' . DeprecatedPhpUseScan::HTTP_RESPONSE_HEADER_SAYS,
        ], $found);
    }

    /**
     * Regression: the guard read the application directories only, and the
     * 82 setAccessible() calls that failed 405 tests on PHP 8.5 were in
     * tests/. It reads every PHP file now, and skips the dependencies, the
     * runtime data and the images.
     */
    public function testTheScanReadsEveryPhpFileOfTheRepository(): void
    {
        $files = DeprecatedPhpUseScan::sourceFilesIn(dirname(__DIR__, 2));

        $expected = ['yii', 'components/RunnerHttpClient.php', 'tests/unit/models/ApprovalRuleTest.php'];
        foreach ([...$expected, 'web/index.php'] as $file) {
            $this->assertContains($file, $files);
        }
        $skipped = '#^(vendor|runtime|docker|web/assets)/|(^|/)node_modules/#';
        $this->assertSame([], array_values(array_filter(
            $files,
            static fn (string $file): bool => preg_match($skipped, $file) === 1
        )));

        $this->directory = sys_get_temp_dir() . '/deprecated_php_calls_' . uniqid('', true);
        $tree = ['a.php', 'yii', 'bin/release', 'notes.txt', 'web/index.php', 'web/assets/x.php', 'sub/vendor/z.php'];
        foreach ($tree as $file) {
            self::write($this->directory . '/' . $file);
        }
        foreach (DeprecatedPhpUseScan::SKIPPED as $skipped) {
            self::write("{$this->directory}/{$skipped}/x.php");
        }
        self::write($this->directory . '/tests/e2e/node_modules/pkg/x.php');

        $this->assertSame(
            ['a.php', 'sub/vendor/z.php', 'web/index.php', 'yii'],
            DeprecatedPhpUseScan::sourceFilesIn($this->directory)
        );
    }

    /**
     * The uses are "<path>:<line>: <what PHP says>" by kind; the declarations
     * are PhpDeprecations::userlandDeclarations()'s.
     *
     * @return array{
     *     uses: array<string, list<string>>,
     *     declarations: array{methods: array<string, list<string>>, classConstants: array<string, list<string>>}
     * }
     */
    private static function repository(): array
    {
        if (self::$repository !== null) {
            return self::$repository;
        }
        $root = dirname(__DIR__, 2);
        $codes = [];
        foreach (DeprecatedPhpUseScan::sourceFilesIn($root) as $path) {
            $codes[$path] = (string)file_get_contents($root . '/' . $path);
        }
        $deprecations = PhpDeprecations::ofRunningPhp();
        $declarations = $deprecations->userlandDeclarations(self::userlandCode($codes, $root));
        $scan = new DeprecatedPhpUseScan($deprecations->without($declarations));
        $uses = [];
        foreach ($codes as $path => $code) {
            foreach ($scan->usesIn($code) as $use) {
                $uses[$use['kind']][] = "{$path}:{$use['line']}: {$use['says']}";
            }
        }

        return self::$repository = ['uses' => $uses, 'declarations' => $declarations];
    }

    /**
     * The deprecations of the running PHP without the names that userland
     * declares too, as the scan of the repository checks them.
     */
    private static function checkedDeprecations(): PhpDeprecations
    {
        $declarations = self::repository()['declarations'];

        return PhpDeprecations::ofRunningPhp()->without($declarations);
    }

    /**
     * The code of every userland file a call can reach: the repository's,
     * and each file of the dependencies that the autoloader's classmap
     * names (the dev entrypoint and every image install with
     * --optimize-autoloader, so it names every class). The classmap names
     * real paths, so vendor/ is compared as one too.
     *
     * @param array<string, string> $codes the repository's files, by path
     * @return \Generator<string, string>
     */
    private static function userlandCode(array $codes, string $root): \Generator
    {
        yield from $codes;
        $vendor = (string)realpath($root . '/vendor');
        $classmap = require $vendor . '/composer/autoload_classmap.php';
        $files = array_unique(array_values($classmap));
        sort($files);
        foreach ($files as $file) {
            if (str_starts_with($file, $vendor . '/') && is_file($file)) {
                yield 'vendor/' . substr($file, strlen($vendor) + 1) => (string)file_get_contents($file);
            }
        }
    }

    /**
     * Deprecations made up for the tests of the scan.
     */
    private static function madeUpDeprecations(): PhpDeprecations
    {
        return new PhpDeprecations(
            ['curl_close' => 'F curl_close', 'xml_parser_free' => 'F xml_parser_free'],
            ['setaccessible' => 'M setAccessible'],
            ['E_STRICT' => 'C E_STRICT'],
            ['MYSQL_ATTR_INIT_COMMAND' => 'K MYSQL_ATTR_INIT_COMMAND']
        );
    }

    /**
     * The lines of the uses $scan finds in $code, by kind.
     *
     * @return array<string, list<int>>
     */
    private static function linesByKind(DeprecatedPhpUseScan $scan, string $code): array
    {
        $lines = [];
        foreach ($scan->usesIn($code) as $use) {
            $lines[$use['kind']][] = $use['line'];
        }

        return $lines;
    }

    private static function write(string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700, true);
        }
        file_put_contents($file, "<?php\n");
    }
}

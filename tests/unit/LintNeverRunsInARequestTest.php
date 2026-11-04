<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard: ansible-lint never runs in a web request.
 *
 * Regression: saving a job template waited for ansible-lint. The web
 * actions called LintService::runForTemplate() after the save, and Run Lint
 * LintService::runForProject(): about two seconds for a small playbook, and
 * up to 180 seconds while another lint run held the lock of the checkout,
 * longer than nginx waits for php-fpm. A request queues a lint run now
 * (LintService::queueForTemplate(), queueForProject()), which the
 * queue-worker makes (LintTemplateJob, LintProjectJob).
 *
 * The scan reads the PHP tokens of every file under controllers/ and views/
 * and reports each call of runForTemplate() or runForProject(), on an
 * object or a class; comments and strings do not count.
 */
final class LintNeverRunsInARequestTest extends TestCase
{
    /** The methods of LintService that run ansible-lint. */
    private const RUNS = ['runfortemplate', 'runforproject'];

    /** What may come before the name of a called method. */
    private const CALL_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    /** The directories whose code serves requests. */
    private const SCANNED = ['controllers', 'views'];

    public function testNoControllerOrViewRunsLint(): void
    {
        $found = [];
        foreach (self::scannedFiles() as $relative => $path) {
            foreach (self::lintRuns((string)file_get_contents($path)) as $line => $method) {
                $found[] = sprintf('%s:%d %s()', $relative, $line, $method);
            }
        }

        $this->assertSame(
            [],
            $found,
            'A request queues a lint run (LintService::queueForTemplate(), queueForProject()); the queue-worker runs it.'
        );
    }

    /**
     * The scan reads the files of both directories, so it cannot pass for
     * having read none: the controllers that queue lint runs and the views
     * of their pages are among them.
     */
    public function testTheScanReadsTheControllersAndTheViews(): void
    {
        $files = array_keys(self::scannedFiles());

        foreach (
            [
                'controllers/JobTemplateController.php',
                'controllers/ProjectController.php',
                'controllers/api/v1/JobTemplatesController.php',
                'controllers/api/v1/ProjectsController.php',
                'views/job-template/view.php',
                'views/project/view.php',
            ] as $file
        ) {
            $this->assertContains($file, $files);
        }
        $this->assertGreaterThan(100, count($files));
    }

    /**
     * What counts as a run: a call on an object or a class, also nullsafe
     * and in any case; not a comment, a string, a function of that name or
     * the declaration of the method.
     */
    public function testThePatternFindsEveryCallAndNothingElse(): void
    {
        $this->assertSame([3 => 'runForTemplate'], self::lintRuns("<?php\n\$a = 1;\n\$lint->runForTemplate(\$t);\n"));
        $this->assertSame([2 => 'runForProject'], self::lintRuns("<?php\n\$lint?->runForProject(\$p, 5);"));
        $this->assertSame([2 => 'RunForTemplate'], self::lintRuns("<?php\nLintService::RunForTemplate(\$t);"));
        $this->assertSame([3 => 'runForProject'], self::lintRuns("<?php\n\$x\n    ->runForProject (\$p);"));
        foreach (
            [
                "<?php\n// \$lint->runForTemplate(\$t);",
                "<?php\n/** LintService::runForProject() */",
                "<?php\n\$s = '\$lint->runForTemplate(\$t)';",
                "<?php\nrunForTemplate(\$t);",
                "<?php\nfunction runForProject(\$p) {}",
                "<?php\n\$lint->queueForTemplate(\$t);",
                "<?php\n\$lint->runForTemplates;",
            ] as $code
        ) {
            $this->assertSame([], self::lintRuns($code), $code);
        }
    }

    /**
     * The calls of the lint runs in $code: line => the method's name as
     * written.
     *
     * @return array<int, string>
     */
    private static function lintRuns(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $runs = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), self::RUNS, true)) {
                continue;
            }
            $before = $tokens[$index - 1] ?? null;
            $after = $tokens[$index + 1] ?? null;
            if (is_array($before) && in_array($before[0], self::CALL_OPERATORS, true) && $after === '(') {
                $runs[$token[2]] = $token[1];
            }
        }

        return $runs;
    }

    /**
     * Every PHP file under the scanned directories, by its path relative to
     * the repository.
     *
     * @return array<string, string>
     */
    private static function scannedFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::SCANNED as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
                }
            }
        }
        ksort($files);

        return $files;
    }
}

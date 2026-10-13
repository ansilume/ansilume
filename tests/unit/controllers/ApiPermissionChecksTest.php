<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use PHPUnit\Framework\TestCase;

/**
 * Every check of the signed-in caller's permission in the REST API
 * (controllers/api/v1) goes through BaseApiController::userCan(), which lets
 * superadmins pass, as the gate in BaseApiController::beforeAction() and the
 * web UI's access rules do.
 *
 * Regression: actions checked the permission a second time with
 * \Yii::$app->user->can(), which knows nothing of superadmins. A superadmin
 * without RBAC roles passed the gate and was then refused with 403 by the
 * action itself: creating, updating and deleting job templates, and most
 * other writes of the API.
 *
 * The scan reads the PHP tokens of every file under controllers/api/v1 and
 * reports each call of ->can() or ->checkAccess() outside the methods listed
 * in ALLOWED. A check of another user's permission (not the caller's) needs
 * an entry there, with the reason.
 */
final class ApiPermissionChecksTest extends TestCase
{
    /**
     * Calls that may ask the RBAC manager directly: "<file relative to
     * controllers/api/v1>::<method>" => why.
     */
    private const ALLOWED = [
        'BaseApiController.php::userCan' => 'the check itself: superadmins first, then the RBAC manager',
    ];

    /** Method names that ask the RBAC manager for a permission. */
    private const CHECKS = ['can', 'checkaccess'];

    public function testEveryCallerPermissionCheckGoesThroughUserCan(): void
    {
        $found = [];
        foreach (self::apiFiles() as $relative => $path) {
            foreach (self::permissionChecks((string)file_get_contents($path)) as $check) {
                if (!isset(self::ALLOWED[$relative . '::' . $check['method']])) {
                    $found[] = sprintf('%s:%d ->%s() in %s()', $relative, $check['line'], $check['call'], $check['method']);
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "Check the caller's permission with \$this->userCan('<permission>'), so superadmins pass as at the gate."
        );
    }

    public function testTheScanReadsEveryApiController(): void
    {
        $files = array_keys(self::apiFiles());

        $this->assertContains('BaseApiController.php', $files);
        $this->assertContains('JobTemplatesController.php', $files);
        $this->assertContains('traits/ApiTeamScopingTrait.php', $files);
        $controllers = glob(dirname(__DIR__, 3) . '/controllers/api/v1/*Controller.php');
        $this->assertIsArray($controllers);
        $this->assertGreaterThan(20, count($controllers));
        foreach ($controllers as $controller) {
            $this->assertContains(basename($controller), $files);
        }
    }

    /**
     * Every allowed entry still names a check, so the list cannot keep a
     * method that no longer exists or no longer needs the exception.
     */
    public function testEveryAllowedEntryIsStillACheck(): void
    {
        $files = self::apiFiles();
        foreach (array_keys(self::ALLOWED) as $entry) {
            [$file, $method] = explode('::', $entry);
            $this->assertArrayHasKey($file, $files, $entry);
            $methods = array_column(self::permissionChecks((string)file_get_contents($files[$file])), 'method');
            $this->assertContains($method, $methods, $entry);
        }
    }

    /**
     * The scan finds each way of writing such a call, and nothing else.
     */
    public function testTheScanFindsEveryFormOfAPermissionCheck(): void
    {
        $code = <<<'PHP'
            <?php
            class Sample
            {
                public function actionA(): void
                {
                    if (\Yii::$app->user->can('a')) {
                    }
                    $user = \Yii::$app->user;
                    $user?->can('b');
                    $user -> Can ('c');
                    \Yii::$app->authManager->checkAccess(1, 'd');
                    $this->userCan('e');
                    $canceled = $this->can;
                    $fn = function () use ($user): bool {
                        return $user->can('f');
                    };
                }

                private function helper(): bool
                {
                    // ->can('in a comment') is no call
                    return $this->canView() && \Yii::$app->getUser()->can('g');
                }
            }
            PHP;

        $this->assertSame(
            [
                ['line' => 6, 'call' => 'can', 'method' => 'actionA'],
                ['line' => 9, 'call' => 'can', 'method' => 'actionA'],
                ['line' => 10, 'call' => 'Can', 'method' => 'actionA'],
                ['line' => 11, 'call' => 'checkAccess', 'method' => 'actionA'],
                ['line' => 15, 'call' => 'can', 'method' => 'actionA'],
                ['line' => 22, 'call' => 'can', 'method' => 'helper'],
            ],
            self::permissionChecks($code)
        );
    }

    /**
     * Every PHP file under controllers/api/v1, by path relative to it.
     *
     * @return array<string, string>
     */
    private static function apiFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/controllers/api/v1/';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root))] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The calls ->can(...) / ?->can(...) / ->checkAccess(...) in $code, with
     * their line and the named function they are in (a closure counts as
     * part of the method around it).
     *
     * @return list<array{line: int, call: string, method: string}>
     */
    private static function permissionChecks(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $checks = [];
        $method = '';
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_FUNCTION && is_array($tokens[$index + 1] ?? null) && $tokens[$index + 1][0] === T_STRING) {
                $method = $tokens[$index + 1][1];
                continue;
            }
            if (!in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $name = $tokens[$index + 1] ?? null;
            if (
                is_array($name)
                && $name[0] === T_STRING
                && in_array(strtolower($name[1]), self::CHECKS, true)
                && ($tokens[$index + 2] ?? null) === '('
            ) {
                $checks[] = ['line' => $name[2], 'call' => $name[1], 'method' => $method];
            }
        }

        return $checks;
    }
}

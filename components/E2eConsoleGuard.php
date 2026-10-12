<?php

declare(strict_types=1);

namespace app\components;

use yii\console\ExitCode;

/**
 * Lets the e2e console commands run only with YII_ENV dev or test.
 *
 * e2e/seed creates users whose passwords are in the public repository, one
 * of them a superadmin, and e2e/teardown deletes every record whose name
 * starts with "e2e-". The release images ship both, so on a real
 * installation (YII_ENV prod, or not set: yii falls back to prod) every e2e
 * action refuses to run. bin/tests-e2e.sh and CI run with YII_ENV dev.
 *
 * The check is in runAction(), before any action, option or help: a
 * beforeAction() that returns false makes the command exit 0. The refusal
 * exits non-zero and says neither "complete" nor "already exists", which
 * bin/tests-e2e.sh reads as a successful seed.
 *
 * For a yii\console\Controller. A trait, so commands/E2eController stays
 * within the class length limit.
 */
trait E2eConsoleGuard
{
    /**
     * @param string $id
     * @param array<int|string, mixed> $params
     * @return mixed the result of the action, or ExitCode::CONFIG when refused
     */
    public function runAction($id, $params = [])
    {
        $environment = $this->yiiEnvironment();
        if (!in_array($environment, ['dev', 'test'], true)) {
            $this->stderr(
                "Refused: the e2e commands run only with YII_ENV dev or test, and YII_ENV is \"{$environment}\" here.\n"
                . "They create users whose passwords are public and delete every record named e2e-*,\n"
                . "so they must never run on a real installation.\n"
            );
            return ExitCode::CONFIG;
        }

        return parent::runAction($id, $params);
    }

    /**
     * The YII_ENV the check reads. A method, so tests can replace it: the
     * constant is "test" in PHPUnit, and PHPStan pins it, so a comparison
     * with the constant itself would look constant.
     */
    protected function yiiEnvironment(): string
    {
        return YII_ENV;
    }
}

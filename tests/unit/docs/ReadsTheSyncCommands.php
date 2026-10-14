<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\RunnerProjectSync;
use yii\base\Module;
use yii\console\Controller;

/**
 * The git commands of a sync, as the code builds them, for the doc
 * contract tests on repository URLs and branches: those a runner runs
 * (RunnerProjectSync, the repository's), and a guard that both paths point
 * a checkout's origin at the project's current URL before they fetch.
 */
trait ReadsTheSyncCommands
{
    /**
     * The commands a runner runs for the checkout $path of the repository
     * $url and its branch $branch: a clone, or those that update an
     * existing checkout.
     *
     * @return list<list<string>>
     */
    private function runnerCommandsFor(bool $isClone, string $url, string $branch, string $path): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $sync = new RunnerProjectSync(new Controller('runner', $app));
        $commands = (new \ReflectionMethod(RunnerProjectSync::class, 'gitCommands'))->invoke($sync, $isClone, $url, $branch, $path);
        $this->assertIsArray($commands);

        /** @var list<list<string>> $commands */
        return $commands;
    }

    /**
     * The server sets origin to the project's current URL before it fetches
     * and resets to what it fetched (ProjectService::gitPull()), and a runner
     * sets origin before it fetches and fast-forwards
     * (RunnerProjectSync::gitCommands()). If a sync ever fetches from the URL
     * a checkout was cloned with again, or a runner resets, the docs must
     * change.
     */
    private function assertSyncsPointOriginAtTheCurrentUrl(): void
    {
        $server = (string)file_get_contents(dirname(__DIR__, 3) . '/services/ProjectService.php');
        $setUrl = strpos($server, "['git', '-C', \$dest, 'remote', 'set-url', '--', 'origin', \$url]");
        $fetch = strpos($server, "['git', '-C', \$dest, 'fetch', '--progress', '--depth', '1', '--', 'origin', \$branch]");
        $reset = strpos($server, "['git', '-C', \$dest, 'reset', '--hard', 'FETCH_HEAD']");
        $this->assertNotFalse($setUrl, 'The server points origin at the current URL.');
        $this->assertNotFalse($fetch, 'The server fetches the branch after "--".');
        $this->assertNotFalse($reset, 'The server resets its checkout to what it fetched.');
        $this->assertTrue($setUrl < $fetch && $fetch < $reset, 'The server sets origin, then fetches, then resets.');

        $path = '/var/www/runtime/projects/5';
        $url = 'https://deploy:token@git.example.com/org/repo.git';
        $this->assertSame(
            [
                ['git', '-C', $path, 'remote', 'set-url', '--', 'origin', $url],
                ['git', '-C', $path, 'fetch', '--', 'origin', 'main'],
                ['git', '-C', $path, 'merge', '--ff-only', 'FETCH_HEAD'],
            ],
            $this->runnerCommandsFor(false, $url, 'main', $path),
            'A runner points origin at the current URL, fetches and fast-forwards.'
        );
    }
}

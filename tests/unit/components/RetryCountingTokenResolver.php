<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerHttpClient;
use app\components\RunnerTokenResolver;

/**
 * Test double: counts registration retry waits instead of sleeping and
 * redirects the token cache into a test temp directory.
 */
class RetryCountingTokenResolver extends RunnerTokenResolver
{
    public int $retryWaits = 0;

    private string $cacheDir;

    public function __construct(RunnerHttpClient $http, \yii\console\Controller $controller, string $cacheDir)
    {
        parent::__construct($http, $controller);
        $this->cacheDir = $cacheDir;
    }

    protected function tokenCacheFile(string $name): string
    {
        return $this->cacheDir . '/runner-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.token';
    }

    protected function waitBeforeRetry(): void
    {
        $this->retryWaits++;
    }
}

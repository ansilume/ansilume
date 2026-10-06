<?php

declare(strict_types=1);

namespace app\tests\unit\components;

/**
 * Controller stub that captures stdout/stderr for message assertions.
 */
class CapturingController extends \yii\console\Controller
{
    public string $capturedStdout = '';
    public string $capturedStderr = '';

    public function stdout($string): int
    {
        $this->capturedStdout .= (string)$string;
        return 0;
    }

    public function stderr($string): int
    {
        $this->capturedStderr .= (string)$string;
        return 0;
    }
}

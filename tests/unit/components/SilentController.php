<?php

declare(strict_types=1);

namespace app\tests\unit\components;

/**
 * Controller stub that suppresses stdout/stderr for tests.
 */
class SilentController extends \yii\console\Controller
{
    public function stdout($string): int
    {
        return 0;
    }

    public function stderr($string): int
    {
        return 0;
    }
}

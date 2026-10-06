<?php

declare(strict_types=1);

namespace app\components;

use yii\base\BootstrapInterface;

/**
 * Console bootstrap: marks every console process (queue-worker, runner,
 * schedule-runner, one-off commands) non-dumpable before it does any work,
 * so child processes running repository code cannot read the worker's
 * environment through /proc. See {@see ProcessHardening}.
 */
final class ProcessHardeningBootstrap implements BootstrapInterface
{
    public bool $applied = false;

    private ProcessHardening $hardening;

    public function __construct(?ProcessHardening $hardening = null)
    {
        $this->hardening = $hardening ?? new ProcessHardening();
    }

    /**
     * @param \yii\base\Application $app
     */
    public function bootstrap($app): void
    {
        $this->applied = $this->hardening->disableDumpable();
        if (!$this->applied) {
            \Yii::info(
                'Process hardening unavailable (' . ProcessHardening::UNAVAILABLE_REASON . '): processes of the '
                . 'same user can read this worker\'s environment via /proc.',
                __METHOD__
            );
        }
    }
}

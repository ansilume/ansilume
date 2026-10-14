<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepCommand;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowStepWriteService;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * Shared fixtures of the step write path tests (WorkflowStepWriteService);
 * use in DbTestCase subclasses.
 */
trait WorkflowStepWriteFixtures
{
    use TeamScopeFixtures;

    protected function writer(): WorkflowStepWriteService
    {
        /** @var WorkflowStepWriteService $writer */
        $writer = \Yii::$app->get('workflowStepWriteService');
        return $writer;
    }

    protected function refusalOf(callable $action): WorkflowStepChangeRefusedException
    {
        try {
            $action();
        } catch (WorkflowStepChangeRefusedException $e) {
            return $e;
        }
        $this->fail('Expected a WorkflowStepChangeRefusedException.');
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function apiCommand(array $body, bool $create = true): WorkflowStepCommand
    {
        $command = WorkflowStepCommand::fromApi($body, $create);
        $this->assertInstanceOf(WorkflowStepCommand::class, $command, is_string($command) ? $command : '');

        return $command;
    }

    /**
     * @param array<string, mixed> $posted the form's WorkflowStep fields
     */
    protected function formCommand(array $posted, mixed $position = null, bool $edit = false): WorkflowStepCommand
    {
        $command = WorkflowStepCommand::fromForm($posted, $position, $edit);
        $this->assertInstanceOf(WorkflowStepCommand::class, $command, is_string($command) ? $command : '');

        return $command;
    }

    /**
     * A saved step named $name (a pause step unless $type says otherwise).
     */
    protected function namedStep(
        int $workflowId,
        string $name,
        int $order,
        string $type = WorkflowStep::TYPE_PAUSE,
        ?int $jobTemplateId = null,
        ?int $ruleId = null
    ): WorkflowStep {
        $step = $this->createWorkflowStep($workflowId, $order, $type, $jobTemplateId, $ruleId);
        $step->name = $name;
        $step->save(false);

        return $step;
    }

    /**
     * A job template of a project of its own, without team restrictions,
     * gated by $ruleId when given.
     */
    protected function jobTemplate(int $createdBy, ?int $ruleId = null): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($createdBy)->id,
            (int)$this->createInventory($createdBy)->id,
            (int)$this->createRunnerGroup($createdBy)->id,
            $createdBy
        );
        $template->approval_rule_id = $ruleId;
        $template->save(false);

        return $template;
    }

    /**
     * The names of the workflow's steps in run order (step order, then ID).
     *
     * @return list<string>
     */
    protected function runOrder(int $workflowId): array
    {
        return array_values(array_map('strval', WorkflowStep::find()
            ->select('name')
            ->where(['workflow_template_id' => $workflowId])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->column()));
    }

    /**
     * The names of $steps, in their order.
     *
     * @param list<WorkflowStep> $steps
     * @return list<string>
     */
    protected function names(array $steps): array
    {
        return array_map(static fn (WorkflowStep $step): string => (string)$step->name, $steps);
    }

    /**
     * The workflow's step audit entries, oldest first.
     *
     * @return list<array{action: string, user_id: int|null, context: mixed}>
     */
    protected function stepAudits(int $workflowId): array
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()
            ->where(['object_type' => 'workflow_template', 'object_id' => $workflowId])
            ->andWhere(['like', 'action', 'workflow-template.step.%', false])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(static fn (AuditLog $entry): array => [
            'action' => $entry->action,
            'user_id' => $entry->user_id === null ? null : (int)$entry->user_id,
            'context' => json_decode((string)$entry->metadata, true),
        ], $entries);
    }

    /**
     * Every column of the workflow's steps, by ID, to show that nothing was
     * written.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function stepRows(int $workflowId): array
    {
        $rows = [];
        foreach (WorkflowStep::find()->where(['workflow_template_id' => $workflowId])->orderBy(['id' => SORT_ASC])->asArray()->all() as $row) {
            $rows[(int)$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Run $action with $before run right before the guard locks the
     * workflow: what another request changes between the checks before the
     * lock and the lock. Returns what $action returned.
     */
    protected function meanwhile(callable $before, callable $action): mixed
    {
        $previous = \Yii::$app->get('workflowStepChangeGuard');
        \Yii::$app->set('workflowStepChangeGuard', new class ($before) extends WorkflowStepChangeGuard {
            /** @var callable(): mixed */
            private $before;

            public function __construct(callable $before)
            {
                parent::__construct();
                $this->before = $before;
            }

            public function change(WorkflowTemplate $template, int $userId, bool $addsApprovalStep, callable $change): mixed
            {
                ($this->before)();

                return parent::change($template, $userId, $addsApprovalStep, $change);
            }
        });
        try {
            return $action();
        } finally {
            \Yii::$app->set('workflowStepChangeGuard', $previous);
        }
    }

    /**
     * The statements $action sends that write: INSERT, UPDATE and DELETE.
     *
     * @return list<string>
     */
    protected function writesOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $writes = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
                && preg_match('~^(INSERT|UPDATE|DELETE)\b~', $message[0]) === 1
            ) {
                $writes[] = $message[0];
            }
        }

        return $writes;
    }
}

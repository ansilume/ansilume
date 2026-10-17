<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowStepChange;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepMapping;
use app\components\WorkflowStepPosition;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * The one write path of a workflow's steps, for the web (the template page)
 * and the API (the per-step endpoints): add, update (edit in place), move
 * and remove one step. Each returns what it did (WorkflowStepChange), which
 * WorkflowStepChangeSummary puts in words.
 *
 * Every change runs through WorkflowStepChangeGuard: in one transaction,
 * with the workflow's row locked, 404 when it was deleted meanwhile, 403
 * (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED) when the user may no longer
 * operate the project of every job step, 403 when the user may not change
 * its steps (rule 12: the steps of a workflow with an approval step or a
 * gated job step, a job step whose job template or its inventory has an
 * approval rule, are for admins), and 409 while a run of it is active. A
 * refusal is a WorkflowStepChangeRefusedException, and nothing is written.
 * The checks run in this order, which the API's answers follow:
 * - add: the step as the command sets it, with only the target of its type
 *   and its IDs read as the model stores them (WorkflowStepReferences); 403
 *   when the user may not add it (approval steps and gated job steps, and
 *   any step of a gated workflow, are for admins:
 *   ApprovalRuleAssignmentPolicy::stepsAddRefusal()); its job template (422
 *   when it does not exist for the user, 403 when they may not operate it);
 *   422 for the model's rules; the guard; then, under the lock, a job
 *   step's job template again (403 with stepsAddRefusal()'s text when it,
 *   or its inventory, got an approval rule meanwhile, or it was moved to an
 *   inventory with one), the save, validated again (a step it
 *   routes to may be gone), and its placement (422 for a position it cannot
 *   take).
 * - update: 404 when the workflow has no such step (STEP_NOT_FOUND); 422 for
 *   another step type (WorkflowStepCommand::TYPE_LOCKED) or a pause step's
 *   unused routes; the command applied to the step; 403 when the
 *   workflow's steps are locked for the user, or the edit gives a job step a
 *   gated job template; its job template as for add, only when it changes;
 *   422 for the model's rules; the guard; then, under the lock, the step
 *   again (404 when it was removed meanwhile), the command, the job template
 *   it gives the step again (403 as for add, when it is another than the
 *   step has), the rules again, and the move when a position is given
 *   (422). An update that changes nothing writes nothing and is not audited
 *   (UNCHANGED); one that moves the step lists position among its changes.
 *   The variable mapping is compared by its JSON value
 *   (WorkflowStepMapping::same()), so a step sent back as the API answers
 *   it is unchanged.
 * - move: 404; the guard; the step again (404); the move (422). A move to
 *   where the step stands writes nothing and is not audited: it is MOVED
 *   with from === to, which WorkflowStepChangeSummary reports as STAYS.
 * - remove: the guard; null when the workflow has no such step; 409 while
 *   other steps route to it (STEP_ROUTED_TO); then the delete and the
 *   renumbering of the others.
 *
 * Each change that writes is audited after the commit (WorkflowStepAudit).
 * The step keeps its ID through an update or a move, so the branches that
 * lead to it and the run history stay.
 */
class WorkflowStepWriteService extends Component
{
    /** A change from the template page. */
    public const SOURCE_WEB = 'web';

    /** A change from the API. */
    public const SOURCE_API = 'api';

    /** sprintf(): the workflow template's ID, the step's ID (404). */
    public const STEP_NOT_FOUND = 'Workflow template #%1$d has no step with ID %2$d.';

    /** A job template the user may see but not operate (403, details job_template_ids). */
    public const JOB_TEMPLATE_FORBIDDEN = 'You may not use this job template in a workflow: '
        . 'that needs operator access to its project.';

    /** The fields an update compares, and lists in its changes when they differ. */
    public const TRACKED_FIELDS = [
        'name',
        'job_template_id',
        'approval_rule_id',
        'on_success_step_id',
        'on_failure_step_id',
        'on_always_step_id',
        'extra_vars_template',
    ];

    /**
     * Add the step $command describes to $template, where its position puts
     * it (the end when it names none), as $userId.
     *
     * @throws WorkflowStepChangeRefusedException (403, 404, 409, 422)
     */
    public function add(WorkflowTemplate $template, WorkflowStepCommand $command, int $userId, string $source): WorkflowStepChange
    {
        $step = new WorkflowStep();
        $step->workflow_template_id = (int)$template->id;
        WorkflowStepReferences::apply($step, $command);
        self::forbidIf($this->policy()->stepsAddRefusal($userId, $template, [$step]));
        $this->references()->assertJobTemplateUsable($userId, $step);
        WorkflowStepReferences::assertValid($step);
        $at = $command->position() ?? WorkflowStepPosition::end();
        $change = $this->guard()->change(
            $template,
            $userId,
            $step->step_type === WorkflowStep::TYPE_APPROVAL,
            fn (): WorkflowStepChange => $this->insert($template, $step, $at, $userId, $source)
        );
        $this->trail()->record($template, $userId, $change);

        return $change;
    }

    /**
     * Change the step $stepId of $template in place, as $command says: the
     * step keeps its ID, so the branches that lead to it and its runs stay.
     *
     * @throws WorkflowStepChangeRefusedException (403, 404, 409, 422)
     */
    public function update(
        WorkflowTemplate $template,
        int $stepId,
        WorkflowStepCommand $command,
        int $userId,
        string $source
    ): WorkflowStepChange {
        $edited = $this->findStep($template, $stepId);
        $typed = WorkflowStepReferences::typedFor($edited, $command);
        $storedTemplateId = $edited->job_template_id;
        WorkflowStepReferences::apply($edited, $typed);
        $policy = $this->policy();
        self::forbidIf(
            $policy->workflowStepsChangeRefusal($userId, $template, false)
                ?? $policy->stepsAddRefusal($userId, $template, [$edited])
        );
        if ($edited->job_template_id !== $storedTemplateId) {
            $this->references()->assertJobTemplateUsable($userId, $edited);
        }
        WorkflowStepReferences::assertValid($edited);
        $change = $this->guard()->change(
            $template,
            $userId,
            false,
            fn (): WorkflowStepChange => $this->edit($template, $stepId, $typed, $userId, $source)
        );
        $this->trail()->record($template, $userId, $change);

        return $change;
    }

    /**
     * Move the step $stepId of $template to $to, as $userId.
     *
     * @throws WorkflowStepChangeRefusedException (403, 404, 409, 422)
     */
    public function move(WorkflowTemplate $template, int $stepId, WorkflowStepPosition $to, int $userId, string $source): WorkflowStepChange
    {
        $this->findStep($template, $stepId);
        $change = $this->guard()->change(
            $template,
            $userId,
            false,
            fn (): WorkflowStepChange => $this->place($template, $stepId, $to, $source)
        );
        $byArrow = in_array($to->kind(), [WorkflowStepPosition::KIND_UP, WorkflowStepPosition::KIND_DOWN], true);
        $this->trail()->record($template, $userId, $change, $byArrow ? $to->kind() : null);

        return $change;
    }

    /**
     * Remove the step $stepId of $template, as $userId. Null when the
     * workflow has no such step (after the guard's checks).
     *
     * @throws WorkflowStepChangeRefusedException (403, 404, 409)
     */
    public function remove(WorkflowTemplate $template, int $stepId, int $userId, string $source): ?WorkflowStepChange
    {
        $change = $this->guard()->change(
            $template,
            $userId,
            false,
            fn (): ?WorkflowStepChange => $this->delete($template, $stepId, $source)
        );
        if ($change !== null) {
            $this->trail()->record($template, $userId, $change);
        }

        return $change;
    }

    /**
     * Save $step and place it at $at, under the lock, once its job template
     * is checked again (assertMayGiveJobTemplate()).
     *
     * @throws WorkflowStepChangeRefusedException (403, 422)
     */
    private function insert(
        WorkflowTemplate $template,
        WorkflowStep $step,
        WorkflowStepPosition $at,
        int $userId,
        string $source
    ): WorkflowStepChange {
        $this->assertMayGiveJobTemplate($userId, $template, $step);
        $before = $this->placement()->orderedSteps((int)$template->id);
        WorkflowStepReferences::assertSaved($step);
        $placed = $this->placement()->insert($step, $at);
        $after = $this->placement()->orderedSteps((int)$template->id);

        return new WorkflowStepChange(WorkflowStepChange::ADDED, $step, 0, $placed['to'], $before, $after, [], $source);
    }

    /**
     * Apply $command to the step $stepId as it is now (applied()), and move
     * it when the command names a position, under the lock. Writes nothing
     * when nothing changes.
     *
     * @throws WorkflowStepChangeRefusedException (403, 404, 422)
     */
    private function edit(
        WorkflowTemplate $template,
        int $stepId,
        WorkflowStepCommand $command,
        int $userId,
        string $source
    ): WorkflowStepChange {
        $step = $this->findStep($template, $stepId);
        $before = $this->placement()->orderedSteps((int)$template->id);
        $from = self::positionIn($before, $stepId);
        $changes = $this->applied($template, $step, $command, $userId);
        $at = $command->position();
        $to = $at === null ? $from : $this->placement()->move($step, $at)['to'];
        if ($to !== $from) {
            $changes['position'] = ['from' => $from, 'to' => $to];
        }
        if ($changes === []) {
            return new WorkflowStepChange(WorkflowStepChange::UNCHANGED, $step, $from, $from, $before, $before, [], $source);
        }
        WorkflowStepReferences::assertSaved($step);
        $after = $this->placement()->orderedSteps((int)$template->id);

        return new WorkflowStepChange(WorkflowStepChange::UPDATED, $step, $from, $to, $before, $after, $changes, $source);
    }

    /**
     * Apply $command to $step under the lock, and return what it changes
     * (differences()): a job template it gives the step is checked again
     * (assertMayGiveJobTemplate()), then the model's rules. The same variable
     * mapping in other words, such as [] for none or other spaces, is no
     * change and keeps its stored text, so that a save does not write it.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     * @throws WorkflowStepChangeRefusedException (403, 422)
     */
    private function applied(WorkflowTemplate $template, WorkflowStep $step, WorkflowStepCommand $command, int $userId): array
    {
        $stored = self::tracked($step);
        WorkflowStepReferences::apply($step, $command);
        if ($step->job_template_id !== $stored['job_template_id']) {
            $this->assertMayGiveJobTemplate($userId, $template, $step);
        }
        WorkflowStepReferences::assertValid($step);
        $changes = self::differences($stored, self::tracked($step));
        if (!array_key_exists('extra_vars_template', $changes)) {
            $step->setAttribute('extra_vars_template', $stored['extra_vars_template']);
        }

        return $changes;
    }

    /**
     * 403 when $userId may not give the job step $step its job template,
     * checked again under the workflow's lock with the rule the caller
     * checked before it (rule 12, ApprovalRuleAssignmentPolicy::
     * stepsAddRefusal()): an approval rule that an admin assigned since then
     * to the job template or to its inventory, or an inventory with one that
     * the job template was given, makes it a gated job step, which is for
     * admins. Other step types have no job template, and the guard has
     * checked the workflow's own steps again. Like every read of a step
     * change after the workflow's row lock, the re-check takes no lock (C16):
     * a rule committed after this transaction's first read is not seen, a
     * documented race.
     *
     * Regression: the rule was checked only before the lock, so a job
     * template that got an approval rule in between was given to the step
     * all the same.
     *
     * @throws WorkflowStepChangeRefusedException (403)
     */
    private function assertMayGiveJobTemplate(int $userId, WorkflowTemplate $template, WorkflowStep $step): void
    {
        if ($step->step_type === WorkflowStep::TYPE_JOB) {
            self::forbidIf($this->policy()->stepsAddRefusal($userId, $template, [$step]));
        }
    }

    /**
     * Move the step $stepId as it is now to $to, under the lock.
     *
     * @throws WorkflowStepChangeRefusedException (404, 422)
     */
    private function place(WorkflowTemplate $template, int $stepId, WorkflowStepPosition $to, string $source): WorkflowStepChange
    {
        $step = $this->findStep($template, $stepId);
        $before = $this->placement()->orderedSteps((int)$template->id);
        $placed = $this->placement()->move($step, $to);
        if ($placed['from'] === $placed['to']) {
            return new WorkflowStepChange(WorkflowStepChange::MOVED, $step, $placed['from'], $placed['to'], $before, $before, [], $source);
        }
        $after = $this->placement()->orderedSteps((int)$template->id);

        return new WorkflowStepChange(WorkflowStepChange::MOVED, $step, $placed['from'], $placed['to'], $before, $after, [], $source);
    }

    /**
     * Delete the step $stepId unless other steps route to it, and renumber
     * the others, under the lock. Null when there is no such step.
     *
     * @throws WorkflowStepChangeRefusedException (409)
     */
    private function delete(WorkflowTemplate $template, int $stepId, string $source): ?WorkflowStepChange
    {
        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => (int)$template->id]);
        if ($step === null) {
            return null;
        }
        $before = $this->placement()->orderedSteps((int)$template->id);
        $positions = array_map(static fn (int $index): int => $index + 1, array_flip(self::ids($before)));
        $this->guard()->assertNotRoutedTo($step, $positions);
        $step->delete();
        $this->placement()->renumber((int)$template->id);
        $after = $this->placement()->orderedSteps((int)$template->id);

        return new WorkflowStepChange(WorkflowStepChange::REMOVED, $step, $positions[$stepId] ?? 0, 0, $before, $after, [], $source);
    }

    /**
     * The step $stepId of $template; 404 (STEP_NOT_FOUND) when the workflow
     * has none, or no longer has it.
     *
     * @throws WorkflowStepChangeRefusedException (404)
     */
    private function findStep(WorkflowTemplate $template, int $stepId): WorkflowStep
    {
        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => (int)$template->id]);
        if ($step === null) {
            throw new WorkflowStepChangeRefusedException(
                sprintf(self::STEP_NOT_FOUND, (int)$template->id, $stepId),
                WorkflowStepChangeRefusedException::NOT_FOUND
            );
        }

        return $step;
    }

    /**
     * 403 with $refusal, unless there is none.
     *
     * @throws WorkflowStepChangeRefusedException (403)
     */
    private static function forbidIf(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new WorkflowStepChangeRefusedException($refusal, WorkflowStepChangeRefusedException::FORBIDDEN);
        }
    }

    /**
     * The TRACKED_FIELDS of $step, by field.
     *
     * @return array<string, mixed>
     */
    private static function tracked(WorkflowStep $step): array
    {
        $values = [];
        foreach (self::TRACKED_FIELDS as $field) {
            $values[$field] = $step->getAttribute($field);
        }

        return $values;
    }

    /**
     * The fields whose value in $after is not the one in $before: exactly
     * the same value, except the variable mapping, which is compared by its
     * JSON value (WorkflowStepMapping::same(): none is null, '', [] or {},
     * and whitespace and the order of keys do not count).
     *
     * Regression: the mapping was compared as text, so a client that sent a
     * step back as the API answers it, [] for no mapping or the stored
     * mapping without its spaces, changed the variable mapping: the column
     * was written and audited although nothing changed.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function differences(array $before, array $after): array
    {
        $changes = [];
        foreach ($before as $field => $value) {
            $new = $after[$field] ?? null;
            $same = $field === 'extra_vars_template'
                ? WorkflowStepMapping::same(WorkflowStepMapping::textOf($value), WorkflowStepMapping::textOf($new))
                : $value === $new;
            if (!$same) {
                $changes[$field] = ['from' => $value, 'to' => $new];
            }
        }

        return $changes;
    }

    /**
     * The 1-based position of the step $stepId among $steps, which hold it.
     *
     * @param list<WorkflowStep> $steps
     */
    private static function positionIn(array $steps, int $stepId): int
    {
        return (int)array_search($stepId, self::ids($steps), true) + 1;
    }

    /**
     * @param list<WorkflowStep> $steps
     * @return list<int>
     */
    private static function ids(array $steps): array
    {
        return array_map(static fn (WorkflowStep $step): int => (int)$step->id, $steps);
    }

    private function placement(): WorkflowStepReorderService
    {
        /** @var WorkflowStepReorderService $placement */
        $placement = \Yii::$app->get('workflowStepReorderService');
        return $placement;
    }

    private function guard(): WorkflowStepChangeGuard
    {
        /** @var WorkflowStepChangeGuard $guard */
        $guard = \Yii::$app->get('workflowStepChangeGuard');
        return $guard;
    }

    private function policy(): ApprovalRuleAssignmentPolicy
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');
        return $policy;
    }

    private function references(): WorkflowStepReferences
    {
        /** @var WorkflowAccessChecker $workflows */
        $workflows = \Yii::$app->get('workflowAccessChecker');
        return new WorkflowStepReferences($workflows);
    }

    private function trail(): WorkflowStepAudit
    {
        /** @var AuditService $auditService */
        $auditService = \Yii::$app->get('auditService');
        return new WorkflowStepAudit($auditService);
    }
}

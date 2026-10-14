<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\WorkflowStepChange;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\components\WorkflowStepSerializer;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepWriteService;
use yii\filters\VerbFilter;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

/**
 * API v1: one step of a workflow template at a time.
 *
 * POST   /api/v1/workflow-templates/{id}/steps                adds a step (201)
 * PUT    /api/v1/workflow-templates/{id}/steps/{stepId}       changes it in place
 * POST   /api/v1/workflow-templates/{id}/steps/{stepId}/move  moves it
 * DELETE /api/v1/workflow-templates/{id}/steps/{stepId}       removes it
 *
 * A step keeps its ID through a change or a move, so the branches that lead
 * to it and the run history stay; the other steps keep theirs. (A "steps"
 * list on PUT /api/v1/workflow-templates/{id} replaces every step instead:
 * new IDs, no branches.) A PUT changes the fields its body sends: an omitted
 * field stays, null clears a route (the run goes on with the next step) or
 * the variable mapping, 0 as a route ends the workflow; step_type cannot
 * change. position ("start", "end" or 1..n) or after_step_id places an added
 * step (the end by default) and moves an updated one; a move takes exactly
 * one of position, after_step_id and direction ("up", "down"). The bodies
 * are read by WorkflowStepCommand and WorkflowStepPosition; the step write
 * path (WorkflowStepWriteService) checks and writes, audited with source
 * api.
 *
 * Every action needs workflow-template.update, then operator access to the
 * project of every job step of the workflow (team scope), checked here
 * before the write path, and again by the write path with the workflow
 * locked (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, the same 403
 * "Forbidden."). The answers, in this order (C13):
 * 1. 401 without a valid API token;
 * 2. 403 FORBIDDEN without workflow-template.update;
 * 3. 404 (a NotFoundHttpException) for a workflow template that does not
 *    exist or was deleted;
 * 4. 403 FORBIDDEN without operator access to every job step's project;
 * 5. 404 STEP_NOT_FOUND (WorkflowStepWriteService) for a step that is not
 *    one of the workflow's (not on POST);
 * 6. 422 for a body that cannot be read (on PUT also, from the write path,
 *    another step_type than the step has, or a route a pause step never
 *    takes);
 * then the write path's refusals, each in the API's error shape with its
 * details: 403 for gated steps (rule 12: approval steps and job steps whose
 * job template has an approval rule are for admins), 422 or 403 for the job
 * template (job_template_ids), 422 for the model's rules, 404 when the
 * workflow was deleted meanwhile, 403 FORBIDDEN when operator access to
 * every job step's project was lost meanwhile, 403 when the lock re-check
 * of gated steps refuses, 409 while a run is active (workflow_job_ids), 404
 * when the step was removed meanwhile, 403
 * (ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED) when the job
 * template the request gives a job step got an approval rule meanwhile, 422
 * for a position the step cannot take, and on DELETE 409 while other steps
 * route to it (step_ids).
 *
 * A write answers {data, change}: data is the step as the workflow lists it
 * (WorkflowStepSerializer), with its position and routes after the change;
 * DELETE answers data {deleted: true, id}. change is
 * WorkflowStepChangeSummary's {message, warnings}: where the step landed or
 * what changed, how empty branches of other steps now lead elsewhere, and
 * warnings when runs now start with another step or a job step no longer
 * waits for an approval step. A PUT or a move that changes nothing answers
 * 200 with what the step is ("Step "X" is unchanged.", "Step "X" stays at
 * position N.") and writes no audit entry.
 *
 * Each action takes only its own verb. The URL rules route the documented
 * paths for that verb only; Yii also resolves the undocumented default route
 * /api/v1/workflow-steps/<action>?id=N&stepId=M, where any other verb
 * answers 405 in the API's error shape, before authentication, so that a GET
 * there changes nothing.
 */
class WorkflowStepsController extends BaseApiController
{
    use ApiTeamScopingTrait;

    /** The answer without the permission (as the gate words it) or without team access. */
    public const FORBIDDEN = 'Forbidden.';

    /** The one verb each action takes. */
    private const VERBS = [
        'create' => ['POST'],
        'update' => ['PUT'],
        'move' => ['POST'],
        'delete' => ['DELETE'],
    ];

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        // After the content negotiator, so that a 405 is JSON.
        $behaviors['verbs'] = ['class' => VerbFilter::class, 'actions' => self::VERBS];

        return $behaviors;
    }

    /**
     * The verb filter throws MethodNotAllowedHttpException, which the error
     * handler would render in the framework's shape; answer it in the API's
     * shape instead, as the access rules answer 403. The filter has already
     * set the Allow header to the action's verb, which its message names.
     */
    public function beforeAction($action): bool
    {
        try {
            return parent::beforeAction($action);
        } catch (MethodNotAllowedHttpException $e) {
            \Yii::$app->response->data = $this->error($e->getMessage(), 405);

            return false;
        }
    }

    protected function apiAccessRules(): array
    {
        return [
            'create' => 'workflow-template.update',
            'update' => 'workflow-template.update',
            'move' => 'workflow-template.update',
            'delete' => 'workflow-template.update',
        ];
    }

    /**
     * Add the step the body describes, where position or after_step_id
     * puts it (the end by default); 201.
     *
     * @return array<string, mixed>
     */
    public function actionCreate(int $id): array
    {
        $template = $this->findOperable($id);
        if ($template === null) {
            return $this->error(self::FORBIDDEN, 403);
        }
        $command = WorkflowStepCommand::fromApi($this->body(), true);
        if (is_string($command)) {
            return $this->error($command, 422);
        }

        return $this->written(
            fn (): WorkflowStepChange => $this->writer()->add($template, $command, $this->userId(), WorkflowStepWriteService::SOURCE_API),
            201
        );
    }

    /**
     * Change the step $stepId in place: the fields the body sends, and its
     * place when it sends position or after_step_id.
     *
     * @return array<string, mixed>
     */
    public function actionUpdate(int $id, int $stepId): array
    {
        $template = $this->findOperable($id);
        if ($template === null) {
            return $this->error(self::FORBIDDEN, 403);
        }
        if (!self::hasStep($template, $stepId)) {
            return $this->stepNotFound($template, $stepId);
        }
        $command = WorkflowStepCommand::fromApi($this->body(), false);
        if (is_string($command)) {
            return $this->error($command, 422);
        }

        return $this->written(
            fn (): WorkflowStepChange => $this->writer()->update($template, $stepId, $command, $this->userId(), WorkflowStepWriteService::SOURCE_API)
        );
    }

    /**
     * Move the step $stepId to where the body's position, after_step_id or
     * direction puts it.
     *
     * @return array<string, mixed>
     */
    public function actionMove(int $id, int $stepId): array
    {
        $template = $this->findOperable($id);
        if ($template === null) {
            return $this->error(self::FORBIDDEN, 403);
        }
        if (!self::hasStep($template, $stepId)) {
            return $this->stepNotFound($template, $stepId);
        }
        $position = WorkflowStepPosition::fromApiMove($this->body());
        if (is_string($position)) {
            return $this->error($position, 422);
        }

        return $this->written(
            fn (): WorkflowStepChange => $this->writer()->move($template, $stepId, $position, $this->userId(), WorkflowStepWriteService::SOURCE_API)
        );
    }

    /**
     * Remove the step $stepId; refused (409) while other steps route to it.
     *
     * @return array<string, mixed>
     */
    public function actionDelete(int $id, int $stepId): array
    {
        $template = $this->findOperable($id);
        if ($template === null) {
            return $this->error(self::FORBIDDEN, 403);
        }
        if (!self::hasStep($template, $stepId)) {
            return $this->stepNotFound($template, $stepId);
        }
        try {
            $change = $this->writer()->remove($template, $stepId, $this->userId(), WorkflowStepWriteService::SOURCE_API);
        } catch (WorkflowStepChangeRefusedException $e) {
            return $this->errorWithDetails($e->getMessage(), $e->status, $e->details);
        }
        if ($change === null) {
            // Another request removed it after the lookup above.
            return $this->stepNotFound($template, $stepId);
        }

        return $this->success(['deleted' => true, 'id' => $stepId]) + ['change' => WorkflowStepChangeSummary::of($change)->toArray()];
    }

    /**
     * The workflow template $id, or null when the caller may not operate
     * every job step's project.
     *
     * @throws NotFoundHttpException when it does not exist or was deleted
     */
    private function findOperable(int $id): ?WorkflowTemplate
    {
        /** @var WorkflowTemplate|null $template */
        $template = WorkflowTemplate::findOne($id);
        if ($template === null) {
            throw new NotFoundHttpException(sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, $id));
        }
        /** @var WorkflowAccessChecker $workflows */
        $workflows = \Yii::$app->get('workflowAccessChecker');

        return $workflows->canOperateWorkflowTemplate($this->userId(), $id) ? $template : null;
    }

    /**
     * Run the write $write and answer the step after it with the change in
     * words, or the refusal in the API's error shape with its details.
     *
     * @param callable(): WorkflowStepChange $write
     * @return array<string, mixed>
     */
    private function written(callable $write, int $status = 200): array
    {
        try {
            $change = $write();
        } catch (WorkflowStepChangeRefusedException $e) {
            return $this->errorWithDetails($e->getMessage(), $e->status, $e->details);
        }
        $summary = WorkflowStepChangeSummary::of($change)->toArray();
        $step = (new WorkflowStepSerializer($change->stepsAfter))->step((int)$change->step->id);

        return $this->success($step, $status) + ['change' => $summary];
    }

    /**
     * Whether $stepId is a step of $template.
     */
    private static function hasStep(WorkflowTemplate $template, int $stepId): bool
    {
        return WorkflowStep::find()->where(['id' => $stepId, 'workflow_template_id' => (int)$template->id])->exists();
    }

    /**
     * @return array{error: array{message: string}}
     */
    private function stepNotFound(WorkflowTemplate $template, int $stepId): array
    {
        return $this->error(sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$template->id, $stepId), 404);
    }

    /**
     * The request body as an array; a body that is no JSON object, such as
     * a list or a string, sets none of the fields.
     *
     * @return array<array-key, mixed>
     */
    private function body(): array
    {
        return (array)\Yii::$app->request->bodyParams;
    }

    /**
     * The caller; the gate let only signed-in users through.
     */
    private function userId(): int
    {
        return (int)$this->currentUserId();
    }

    private function writer(): WorkflowStepWriteService
    {
        /** @var WorkflowStepWriteService $writer */
        $writer = \Yii::$app->get('workflowStepWriteService');
        return $writer;
    }
}

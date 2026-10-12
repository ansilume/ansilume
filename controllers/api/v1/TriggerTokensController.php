<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\JobTemplate;
use app\models\WorkflowTemplate;
use app\services\TriggerTokenService;
use app\services\WorkflowAccessChecker;
use yii\filters\VerbFilter;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

/**
 * API v1: inbound trigger tokens of job and workflow templates.
 *
 * POST   /api/v1/job-templates/{id}/trigger-token       generate or replace
 * DELETE /api/v1/job-templates/{id}/trigger-token       revoke
 * POST   /api/v1/workflow-templates/{id}/trigger-token  generate or replace
 * DELETE /api/v1/workflow-templates/{id}/trigger-token  revoke
 *
 * As in the web UI, this needs job-template.update (workflow-template.update)
 * and operator access to the template's project (to the project of every job
 * step of the workflow). A trigger runs as the user who generated its token,
 * so generating rebinds it to the caller. The raw token appears only in the
 * POST response; only its hash is stored. Revoking is idempotent.
 *
 * Generating takes only POST and revoking only DELETE. The URL rules route
 * the documented paths for these verbs only, so any other verb there gets
 * the 404 of every other unknown API path. Yii also resolves the
 * undocumented default route /api/v1/trigger-tokens/<action>?id=N, for every
 * verb: there, any verb but the action's own answers 405 in the API's error
 * shape, with an Allow header that names the action's verb, before
 * authentication, so nothing is generated, revoked or audited.
 */
class TriggerTokensController extends BaseApiController
{
    use ApiTeamScopingTrait;

    /**
     * The one verb each action takes.
     */
    private const VERBS = [
        'generate-for-job-template' => ['POST'],
        'revoke-for-job-template' => ['DELETE'],
        'generate-for-workflow-template' => ['POST'],
        'revoke-for-workflow-template' => ['DELETE'],
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
            'generate-for-job-template' => 'job-template.update',
            'revoke-for-job-template' => 'job-template.update',
            'generate-for-workflow-template' => 'workflow-template.update',
            'revoke-for-workflow-template' => 'workflow-template.update',
        ];
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionGenerateForJobTemplate(int $id): array
    {
        $template = $this->findJobTemplate($id);

        return $this->mayChangeJobTemplate($template) ? $this->generate($template) : $this->error('Forbidden.', 403);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionRevokeForJobTemplate(int $id): array
    {
        $template = $this->findJobTemplate($id);

        return $this->mayChangeJobTemplate($template) ? $this->revoke($template) : $this->error('Forbidden.', 403);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionGenerateForWorkflowTemplate(int $id): array
    {
        $template = $this->findWorkflowTemplate($id);

        return $this->mayChangeWorkflowTemplate($template) ? $this->generate($template) : $this->error('Forbidden.', 403);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionRevokeForWorkflowTemplate(int $id): array
    {
        $template = $this->findWorkflowTemplate($id);

        return $this->mayChangeWorkflowTemplate($template) ? $this->revoke($template) : $this->error('Forbidden.', 403);
    }

    /**
     * 201 with the new raw token and the user the trigger now runs as: the
     * caller. The response must not be cached.
     *
     * @return array{data: mixed}
     */
    private function generate(JobTemplate|WorkflowTemplate $template): array
    {
        $userId = (int)$this->currentUserId();
        $token = $this->tokens()->generate($template, $userId, ['source' => 'api']);
        \Yii::$app->response->headers->set('Cache-Control', 'no-store');

        return $this->success(['token' => $token, 'trigger_user_id' => $userId], 201);
    }

    /**
     * @return array{data: mixed}
     */
    private function revoke(JobTemplate|WorkflowTemplate $template): array
    {
        $this->tokens()->revoke($template, (int)$this->currentUserId(), ['source' => 'api']);

        return $this->success(['revoked' => true]);
    }

    /**
     * Operator access to the template's project; the permission is checked
     * by the access rules.
     */
    private function mayChangeJobTemplate(JobTemplate $template): bool
    {
        $userId = $this->currentUserId();

        return $userId !== null && $this->checker()->canOperateChildResource($userId, $template->project_id);
    }

    /**
     * Operator access to the project of every job step; the permission is
     * checked by the access rules.
     */
    private function mayChangeWorkflowTemplate(WorkflowTemplate $template): bool
    {
        $userId = $this->currentUserId();
        /** @var WorkflowAccessChecker $workflows */
        $workflows = \Yii::$app->get('workflowAccessChecker');

        return $userId !== null && $workflows->canOperateWorkflowTemplate($userId, (int)$template->id);
    }

    private function findJobTemplate(int $id): JobTemplate
    {
        /** @var JobTemplate|null $template */
        $template = JobTemplate::findOne($id);
        if ($template === null) {
            throw new NotFoundHttpException("Template #{$id} not found.");
        }

        return $template;
    }

    private function findWorkflowTemplate(int $id): WorkflowTemplate
    {
        /** @var WorkflowTemplate|null $template */
        $template = WorkflowTemplate::findOne($id);
        if ($template === null) {
            throw new NotFoundHttpException("Workflow template #{$id} not found.");
        }

        return $template;
    }

    private function tokens(): TriggerTokenService
    {
        return new TriggerTokenService();
    }
}

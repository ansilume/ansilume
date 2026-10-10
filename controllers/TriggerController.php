<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\WorkflowTemplate;
use app\services\JobLaunchService;
use app\services\NotificationDispatcher;
use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Inbound trigger endpoints.
 *
 *   POST /trigger/fire?token=…           — fire a Job for a JobTemplate
 *   POST /trigger/fire-workflow?token=…  — launch a WorkflowJob for a WorkflowTemplate
 *
 * Allows external systems (CI pipelines, monitoring tools, etc.) to
 * launch a job or a full workflow without requiring session
 * authentication or a full API token. The trigger token itself is the
 * credential — treat the URL as a secret.
 *
 * A trigger runs as the user who generated its token (the template's
 * creator for tokens generated before that was recorded). Every call checks
 * that this user may still launch: an active user with job.launch (or
 * workflow.launch) and operator access to the project of the job template
 * (or of every job step). Otherwise the call is refused with 403 and
 * audited; the caller learns no team or project details.
 *
 * Optional JSON body for fire:
 *   { "extra_vars": { "env": "prod" }, "limit": "webservers" }
 *
 * Optional JSON body for fire-workflow:
 *   { "extra_vars": { "env": "prod" } }   // applied to the start step only
 *
 * Response (JSON):
 *   201: { "job_id": 42 }              (fire)
 *   201: { "workflow_job_id": 17 }     (fire-workflow)
 *   403: { "error": "Launch refused." } the user the trigger runs as may not launch it
 *   404: token not found or trigger not enabled
 *   500: { "error": "Launch failed." }
 */
class TriggerController extends Controller
{
    /** Audit reason: the trigger's user is not an active user with job.launch. */
    public const DENIED_NOT_PERMITTED = 'not_permitted';

    /** Audit reason: the trigger's user may not operate the template's project. */
    public const DENIED_NO_PROJECT_ACCESS = 'no_project_access';

    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'fire' => ['POST'],
                    'fire-workflow' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Fire a job for the template matching the given trigger token, as the
     * user who generated the token.
     */
    public function actionFire(string $token): Response
    {
        $template = JobTemplate::findByTriggerToken($token);
        if ($template === null) {
            // Fire a notification so operators can spot leaked/stale tokens.
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $dispatcher->dispatch(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, [
                'trigger' => [
                    'token_prefix' => substr($token, 0, 6),
                    'ip' => (string)(\Yii::$app->request->userIP ?? ''),
                    'user_agent' => substr((string)(\Yii::$app->request->userAgent ?? ''), 0, 200),
                ],
            ]);
            throw new NotFoundHttpException('Trigger not found.');
        }

        $launchedBy = $template->getTriggerUserId();
        $denial = $this->launchDenial($launchedBy, $template);
        if ($denial !== null) {
            return $this->refuseJobLaunch($template, $launchedBy, $denial);
        }

        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');

        try {
            $job = $launcher->launch($template, $launchedBy, $this->parseOverrides());
        } catch (\RuntimeException $e) {
            \Yii::error("Trigger for template #{$template->id} failed: " . $e->getMessage(), __CLASS__);
            return $this->asJson(['error' => 'Launch failed.'])->setStatusCode(500);
        }

        \Yii::$app->response->statusCode = 201;
        return $this->asJson(['job_id' => $job->id]);
    }

    /**
     * Launch a workflow for the template matching the given trigger token.
     * Mirror of {@see actionFire()} but for WorkflowTemplate — every step
     * (job + approval + pause) gets dispatched by WorkflowExecutionService,
     * as the user who generated the token.
     *
     * The optional JSON body's `extra_vars` is forwarded as launch
     * overrides. They reach the start step only, when it is a job step;
     * later steps get their extra vars from the previous step's output, as
     * configured on each step.
     */
    public function actionFireWorkflow(string $token): Response
    {
        $template = WorkflowTemplate::findByTriggerToken($token);
        if ($template === null) {
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $dispatcher->dispatch(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, [
                'trigger' => [
                    'token_prefix' => substr($token, 0, 6),
                    'kind' => 'workflow',
                    'ip' => (string)(\Yii::$app->request->userIP ?? ''),
                    'user_agent' => substr((string)(\Yii::$app->request->userAgent ?? ''), 0, 200),
                ],
            ]);
            throw new NotFoundHttpException('Trigger not found.');
        }

        $overrides = $this->parseOverrides();
        // Workflows only honour extra_vars at launch time (limit/verbosity
        // are job-template-scoped). Drop the others silently — operators
        // can still set them per step on the template definition.
        $launchOverrides = [];
        if (!empty($overrides['extra_vars'])) {
            $launchOverrides['extra_vars'] = $overrides['extra_vars'];
        }

        $launchedBy = $template->getTriggerUserId();

        /** @var WorkflowExecutionService $executor */
        $executor = \Yii::$app->get('workflowExecutionService');

        try {
            $workflowJob = $executor->launch($template, $launchedBy, $launchOverrides, 'trigger');
        } catch (\RuntimeException $e) {
            return $this->workflowLaunchFailure($template, $e);
        }

        \Yii::$app->response->statusCode = 201;
        return $this->asJson(['workflow_job_id' => $workflowJob->id]);
    }

    /**
     * Answer a workflow trigger that did not launch: 403 when the user it
     * runs as may not launch the workflow (audited by the access check), 500
     * otherwise. The caller gets no details either way.
     */
    private function workflowLaunchFailure(WorkflowTemplate $template, \RuntimeException $e): Response
    {
        if ($e instanceof WorkflowAccessDeniedException) {
            \Yii::warning("Workflow trigger for template #{$template->id} refused: " . $e->getMessage(), __CLASS__);
            return $this->asJson(['error' => 'Launch refused.'])->setStatusCode(403);
        }
        \Yii::error("Workflow trigger for template #{$template->id} failed: " . $e->getMessage(), __CLASS__);

        return $this->asJson(['error' => 'Launch failed.'])->setStatusCode(500);
    }

    /**
     * Why $userId may not launch $template without a session, or null when
     * they may: they must be an active user with job.launch and operator
     * access to the template's project.
     */
    private function launchDenial(int $userId, JobTemplate $template): ?string
    {
        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');
        if (!$access->isActiveWithPermission($userId, 'job.launch')) {
            return self::DENIED_NOT_PERMITTED;
        }
        /** @var ProjectAccessChecker $projects */
        $projects = \Yii::$app->get('projectAccessChecker');

        return $projects->canOperateChildResource($userId, (int)$template->project_id)
            ? null
            : self::DENIED_NO_PROJECT_ACCESS;
    }

    /**
     * Audit and answer a refused job trigger. The caller only learns that
     * the launch was refused.
     */
    private function refuseJobLaunch(JobTemplate $template, int $launchedBy, string $reason): Response
    {
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TRIGGER_DENIED,
            'job_template',
            (int)$template->id,
            $launchedBy,
            ['reason' => $reason, 'project_id' => (int)$template->project_id]
        );
        \Yii::warning(
            "Trigger for template #{$template->id} refused: user #{$launchedBy}, whom it runs as, may not launch it ({$reason}).",
            __CLASS__
        );

        return $this->asJson(['error' => 'Launch refused.'])->setStatusCode(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOverrides(): array
    {
        $overrides = [];

        $raw = \Yii::$app->request->rawBody;
        if (!empty($raw)) {
            $body = json_decode($raw, true);
            if (is_array($body)) {
                if (!empty($body['extra_vars'])) {
                    $overrides['extra_vars'] = $body['extra_vars'];
                }
                if (!empty($body['limit'])) {
                    $overrides['limit'] = $body['limit'];
                }
                if (isset($body['verbosity'])) {
                    $overrides['verbosity'] = (int)$body['verbosity'];
                }
            }
        }

        return $overrides;
    }
}

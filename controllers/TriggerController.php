<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\WorkflowTemplate;
use app\services\JobLaunchService;
use app\services\NotificationDispatcher;
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
 * Optional JSON body for fire:
 *   { "extra_vars": { "env": "prod" }, "limit": "webservers" }
 *
 * Optional JSON body for fire-workflow:
 *   { "extra_vars": { "env": "prod" } }   // applied to every job step
 *
 * Response (JSON):
 *   201: { "job_id": 42 }              (fire)
 *   201: { "workflow_job_id": 17 }     (fire-workflow)
 *   404: token not found or trigger not enabled
 */
class TriggerController extends Controller
{
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
     * Fire a job for the template matching the given trigger token.
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

        $overrides = $this->parseOverrides();

        // Use the template owner as launcher identity for system-triggered jobs.
        $launchedBy = $template->created_by;

        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');

        try {
            $job = $launcher->launch($template, $launchedBy, $overrides);
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
     * (job + approval + pause) gets dispatched by WorkflowExecutionService.
     *
     * The optional JSON body's `extra_vars` is forwarded as launch
     * overrides; downstream the executor applies them to every job-step
     * the workflow runs (same shape as the manual launch UI).
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

        // Use the template owner as launcher identity for system-triggered runs.
        $launchedBy = (int)$template->created_by;

        /** @var WorkflowExecutionService $executor */
        $executor = \Yii::$app->get('workflowExecutionService');

        try {
            $workflowJob = $executor->launch($template, $launchedBy, $launchOverrides);
        } catch (\RuntimeException $e) {
            \Yii::error("Workflow trigger for template #{$template->id} failed: " . $e->getMessage(), __CLASS__);
            return $this->asJson(['error' => 'Launch failed.'])->setStatusCode(500);
        }

        \Yii::$app->response->statusCode = 201;
        return $this->asJson(['workflow_job_id' => $workflowJob->id]);
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

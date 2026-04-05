<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\services\JobLaunchService;
use app\services\NotificationDispatcher;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Inbound trigger endpoint.
 *
 * POST /trigger/{token}
 *
 * Allows external systems (CI pipelines, monitoring tools, etc.) to
 * launch a job for a specific job template without requiring session
 * authentication or a full API token.
 *
 * The trigger token itself is the credential — treat the URL as a secret.
 *
 * Optional JSON body:
 *   { "extra_vars": { "env": "prod" }, "limit": "webservers" }
 *
 * Response (JSON):
 *   201: { "job_id": 42 }
 *   404: token not found or template has no trigger enabled
 */
class TriggerController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['fire' => ['POST']],
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

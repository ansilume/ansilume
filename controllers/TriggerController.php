<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ErrorText;
use app\components\TriggerTokenResolver;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\WorkflowTemplate;
use app\services\AuditService;
use app\services\JobLaunchInput;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;
use app\services\NotificationDispatcher;
use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Request;
use yii\web\Response;

/**
 * Inbound trigger endpoints.
 *
 *   POST /trigger/fire           — fire a Job for a JobTemplate
 *   POST /trigger/fire-workflow  — launch a WorkflowJob for a WorkflowTemplate
 *
 * Allows external systems (CI pipelines, monitoring tools, etc.) to
 * launch a job or a full workflow without requiring session
 * authentication or a full API token. The trigger token itself is the
 * credential: send it in the X-Trigger-Token header, or as
 * `Authorization: Bearer <token>`. The URL forms ?token=<token> and
 * POST /trigger/<token> still work but are deprecated, because URLs end up
 * in access logs and proxies ({@see TriggerTokenResolver}). Every call
 * records how the token was sent in its audit entry, never the token.
 *
 * A trigger runs as the user who generated its token (the template's
 * creator for tokens generated before that was recorded). Every call checks
 * that this user may still launch: an active user with job.launch (or
 * workflow.launch) and operator access to the project of the job template
 * (or of every job step). Otherwise the call is refused with 403 and
 * audited; the caller learns no team or project details.
 *
 * Optional JSON body for fire:
 *   { "extra_vars": { "env": "prod" }, "limit": "webservers", "verbosity": 2 }
 *
 * Optional JSON body for fire-workflow:
 *   { "extra_vars": { "env": "prod" } }   // stored on the run, they reach every job step
 *
 * Response (JSON unless noted):
 *   201: { "job_id": 42, "status": "queued" }   (fire)
 *                                        status: "queued", or "pending_approval" when
 *                                        the job waits for an approval, which the
 *                                        approval rule of the job template or of its
 *                                        inventory requires (JobTemplateGate)
 *   201: { "workflow_job_id": 17 }     (fire-workflow)
 *   400: no token (HTML error page)
 *   403: { "error": "Launch refused." } the user the trigger runs as may not launch it
 *   404: token not found or trigger not enabled (HTML error page)
 *   405: not a POST (HTML error page)
 *   422: { "error": "<why>" }           the launch was refused for a reason the caller can
 *                                        fix, and nothing was launched: e.g. a password
 *                                        survey answer was the placeholder $encrypted$, or
 *                                        for fire a limit or a verbosity the job record
 *                                        would refuse (JobLaunchInput), or the approval
 *                                        the job would wait for (JobLaunchRejectedException:
 *                                        too few approvers besides the user the trigger
 *                                        runs as, a rule that is gone, a job template and
 *                                        its inventory with different rules, a template
 *                                        or inventory that changed or is gone meanwhile);
 *                                        for fire-workflow also extra_vars that are not a
 *                                        JSON object, a deleted workflow or one without
 *                                        steps, or an approval step that too few approvers
 *                                        besides the user the trigger runs as may decide
 *   500: { "error": "Launch failed." }
 */
class TriggerController extends Controller
{
    /** Audit reason: the trigger's user is not an active user with job.launch. */
    public const DENIED_NOT_PERMITTED = 'not_permitted';

    /** Audit reason: the trigger's user may not operate the template's project. */
    public const DENIED_NO_PROJECT_ACCESS = 'no_project_access';

    /**
     * Logged when a job trigger failed for a reason the caller cannot fix
     * (the answer is a bare 500). sprintf(): the job template's ID, then
     * JobLaunchService::failureReason(): what may be said about the error
     * (ErrorText: its message, but for a database error only its class,
     * SQLSTATE and driver error code), without the "Job launch failed: "
     * of JobLaunchService's own failures.
     */
    public const JOB_TRIGGER_FAILED = 'Trigger for template #%d failed: %s';

    /**
     * Logged when a workflow trigger failed for a reason the caller cannot
     * fix (the answer is a bare 500). sprintf(): the workflow template's ID,
     * then what may be said about the error (ErrorText): its message, but
     * for a database error only its class, SQLSTATE and driver error code.
     */
    public const WORKFLOW_TRIGGER_FAILED = 'Workflow trigger for template #%d failed: %s';

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
     * Fire a job for the template matching the presented trigger token, as
     * the user who generated the token. 201 with the job's ID and status:
     * queued, or pending_approval when the job waits for an approval.
     *
     * @throws BadRequestHttpException when the request carries no token
     * @throws NotFoundHttpException when no template has the token
     */
    public function actionFire(): Response
    {
        $presented = $this->presentedToken();
        $template = JobTemplate::findByTriggerToken($presented['token']);
        if ($template === null) {
            // Notify operators so they can spot leaked or stale tokens.
            $this->notifyUnknownToken($presented['token'], []);
            throw new NotFoundHttpException('Trigger not found.');
        }

        $launchedBy = $template->getTriggerUserId();
        $denial = $this->launchDenial($launchedBy, $template);
        if ($denial !== null) {
            return $this->refuseJobLaunch($template, $launchedBy, $denial, $presented['transport']);
        }

        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');

        try {
            $overrides = $this->parseOverrides();
            // A limit or verbosity the job record would refuse: 422, before the launch.
            JobLaunchInput::check($overrides);
            $job = $launcher->launch($template, $launchedBy, $overrides);
        } catch (\RuntimeException $e) {
            return $this->jobLaunchFailure($template, $e);
        }

        $this->auditFired('job_template', (int)$template->id, $launchedBy, [
            'transport' => $presented['transport'],
            'job_id' => (int)$job->id,
        ]);

        return $this->asJson(['job_id' => (int)$job->id, 'status' => (string)$job->status])->setStatusCode(201);
    }

    /**
     * Launch a workflow for the template matching the presented trigger
     * token. Mirror of {@see actionFire()} but for WorkflowTemplate — every
     * step (job + approval + pause) gets dispatched by
     * WorkflowExecutionService, as the user who generated the token.
     *
     * The optional JSON body's `extra_vars` are the run's launch extra vars:
     * stored on the run, they reach every job step, under the step's own
     * mapping of the previous step's output (WorkflowRunVars). The launch is
     * refused with 422 when they are not a JSON object, or hold the
     * placeholder as a password answer of a job step's template.
     *
     * @throws BadRequestHttpException when the request carries no token
     * @throws NotFoundHttpException when no workflow template has the token
     */
    public function actionFireWorkflow(): Response
    {
        $presented = $this->presentedToken();
        $template = WorkflowTemplate::findByTriggerToken($presented['token']);
        if ($template === null) {
            $this->notifyUnknownToken($presented['token'], ['kind' => 'workflow']);
            throw new NotFoundHttpException('Trigger not found.');
        }

        $launchedBy = $template->getTriggerUserId();

        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');
        /** @var WorkflowExecutionService $executor */
        $executor = \Yii::$app->get('workflowExecutionService');

        try {
            // The launch checks this again; checking here first lets the
            // audited refusal say how the token was sent.
            $access->assertMayLaunch($launchedBy, $template, 'trigger', ['transport' => $presented['transport']]);
            $workflowJob = $executor->launch($template, $launchedBy, $this->workflowOverrides(), 'trigger');
        } catch (\RuntimeException $e) {
            return $this->workflowLaunchFailure($template, $e);
        }

        $this->auditFired('workflow_template', (int)$template->id, $launchedBy, [
            'transport' => $presented['transport'],
            'workflow_job_id' => (int)$workflowJob->id,
        ]);

        return $this->asJson(['workflow_job_id' => $workflowJob->id])->setStatusCode(201);
    }

    /**
     * Every other path under /trigger/. It may hold a token, so it answers
     * 404 from here: an unresolvable route would write the whole path into
     * the error log.
     *
     * @throws NotFoundHttpException always
     */
    public function actionNotFound(): never
    {
        throw new NotFoundHttpException('Page not found.');
    }

    /**
     * The token of the request and how it was sent.
     *
     * @return array{token: non-empty-string, transport: string}
     * @throws BadRequestHttpException when the request carries no token
     */
    private function presentedToken(): array
    {
        $presented = TriggerTokenResolver::resolve($this->webRequest());
        if ($presented === null) {
            throw new BadRequestHttpException('Missing trigger token.');
        }

        return $presented;
    }

    /**
     * Tell operators about a call with an unknown token. Only the token's
     * first six characters go into the notification.
     *
     * @param array<string, string> $details
     */
    private function notifyUnknownToken(#[\SensitiveParameter] string $token, array $details): void
    {
        $request = $this->webRequest();
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, [
            'trigger' => ['token_prefix' => substr($token, 0, 6)] + $details + [
                'ip' => (string)($request->userIP ?? ''),
                'user_agent' => substr((string)($request->userAgent ?? ''), 0, 200),
            ],
        ]);
    }

    /**
     * Answer a job trigger that did not launch: 422 with the reason when the
     * launch was refused for a reason the caller can fix, 500 otherwise.
     */
    private function jobLaunchFailure(JobTemplate $template, \RuntimeException $e): Response
    {
        if ($e instanceof JobLaunchRejectedException) {
            \Yii::warning("Trigger for template #{$template->id} rejected: " . $e->getMessage(), __CLASS__);
            return $this->asJson(['error' => $e->getMessage()])->setStatusCode(422);
        }
        \Yii::error(sprintf(self::JOB_TRIGGER_FAILED, (int)$template->id, JobLaunchService::failureReason($e)), __CLASS__);

        return $this->asJson(['error' => 'Launch failed.'])->setStatusCode(500);
    }

    /**
     * Answer a workflow trigger that did not launch: 403 without details when
     * the user it runs as may not launch the workflow (audited by the access
     * check), 422 with the reason when the launch was refused for a reason the
     * caller can fix (as for a job trigger), 500 otherwise.
     */
    private function workflowLaunchFailure(WorkflowTemplate $template, \RuntimeException $e): Response
    {
        if ($e instanceof WorkflowAccessDeniedException) {
            \Yii::warning("Workflow trigger for template #{$template->id} refused: " . $e->getMessage(), __CLASS__);
            return $this->asJson(['error' => 'Launch refused.'])->setStatusCode(403);
        }
        if ($e instanceof WorkflowLaunchRejectedException) {
            \Yii::warning("Workflow trigger for template #{$template->id} rejected: " . $e->getMessage(), __CLASS__);
            return $this->asJson(['error' => $e->getMessage()])->setStatusCode(422);
        }
        \Yii::error(sprintf(self::WORKFLOW_TRIGGER_FAILED, (int)$template->id, ErrorText::of($e)), __CLASS__);

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
    private function refuseJobLaunch(JobTemplate $template, int $launchedBy, string $reason, string $transport): Response
    {
        $this->audit()->log(
            AuditLog::ACTION_TRIGGER_DENIED,
            'job_template',
            (int)$template->id,
            $launchedBy,
            ['reason' => $reason, 'project_id' => (int)$template->project_id, 'transport' => $transport]
        );
        \Yii::warning(
            "Trigger for template #{$template->id} refused: user #{$launchedBy}, whom it runs as, may not launch it ({$reason}).",
            __CLASS__
        );

        return $this->asJson(['error' => 'Launch refused.'])->setStatusCode(403);
    }

    /**
     * Record that a trigger launched something, as the user it runs as.
     *
     * @param array<string, scalar> $context how the token was sent and what was launched
     */
    private function auditFired(string $objectType, int $templateId, int $launchedBy, array $context): void
    {
        $this->audit()->log(AuditLog::ACTION_TRIGGER_FIRED, $objectType, $templateId, $launchedBy, $context);
    }

    /**
     * The overrides of the JSON body that a workflow takes: only extra_vars
     * (limit and verbosity are job template settings, set per step on the
     * workflow instead). Other keys are dropped silently. extra_vars go to
     * the launch as sent, whenever the body has the key, so that the launch
     * can refuse what is not a JSON object instead of dropping it.
     *
     * @return array<string, mixed>
     */
    private function workflowOverrides(): array
    {
        $body = json_decode($this->webRequest()->getRawBody(), true);

        return is_array($body) && array_key_exists('extra_vars', $body) ? ['extra_vars' => $body['extra_vars']] : [];
    }

    /**
     * The overrides of the JSON body that a job takes: extra_vars, limit and
     * verbosity, as sent. A limit and a verbosity go on whenever they are
     * set, for JobLaunchInput to check, which refuses a list or an object
     * also when it is empty ([] or {}, which json_decode() gives as []).
     *
     * @return array<string, mixed>
     */
    private function parseOverrides(): array
    {
        $overrides = [];

        $raw = $this->webRequest()->getRawBody();
        if ($raw !== '') {
            $body = json_decode($raw, true);
            if (is_array($body)) {
                if (!empty($body['extra_vars'])) {
                    $overrides['extra_vars'] = $body['extra_vars'];
                }
                if (isset($body['limit'])) {
                    $overrides['limit'] = $body['limit'];
                }
                if (isset($body['verbosity'])) {
                    $overrides['verbosity'] = $body['verbosity'];
                }
            }
        }

        return $overrides;
    }

    private function webRequest(): Request
    {
        $request = \Yii::$app->request;
        assert($request instanceof Request);

        return $request;
    }

    private function audit(): AuditService
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');

        return $audit;
    }
}

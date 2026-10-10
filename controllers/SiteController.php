<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTemplate;
use app\models\LoginForm;
use app\models\PasswordResetForm;
use app\models\PasswordResetRequestForm;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\TotpVerifyForm;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\controllers\traits\TeamScopingTrait;
use yii\db\ActiveQuery;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SiteController extends BaseController
{
    use TeamScopingTrait;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['login', 'error', 'forgot-password', 'reset-password', 'verify-totp'], 'allow' => true],
            ['actions' => ['index', 'logout', 'chart-data'], 'allow' => true, 'roles' => ['@']],
        ];
    }

    public function actions(): array
    {
        return ['error' => ['class' => 'yii\web\ErrorAction']];
    }

    /**
     * The dashboard. Team scoping applies to every list and counter: only
     * jobs, schedules, workflows, approval requests and projects the user may
     * see. Panels for a permission the user lacks stay empty. Runners are
     * shared infrastructure and stay unscoped.
     */
    public function actionIndex(): string
    {
        $today = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $jobFilter = $this->checker()->buildJobFilter($this->currentUserId());
        $approvals = $this->pendingApprovalQuery();
        $schedules = $this->scheduleQuery();
        $runningWorkflows = $this->runningWorkflows();
        $cutoff = time() - RunnerGroup::STALE_AFTER;

        return $this->render('index', [
            'stats' => $this->buildStatCards($today, $jobFilter, $approvals),
            'statusCounts' => $this->buildStatusCounts((int)strtotime('-6 days', $today), $jobFilter),
            'recentJobs' => $this->jobs($jobFilter)
                ->with(['jobTemplate', 'launcher'])
                ->orderBy(['id' => SORT_DESC])
                ->limit(10)
                ->all(),
            'runningJobs' => $this->jobs($jobFilter)
                ->with(['jobTemplate', 'launcher'])
                ->andWhere(['status' => Job::STATUS_RUNNING])
                ->orderBy(['id' => SORT_DESC])
                ->all(),
            'templates' => $this->quickLaunchTemplates(),
            'workflowTemplates' => $this->quickLaunchWorkflows(),
            'onlineRunners' => (int)Runner::find()->where(['>=', 'last_seen_at', $cutoff])->count(),
            'totalRunners' => (int)Runner::find()->count(),
            'outdatedRunners' => $this->countOutdatedRunners(),
            'pendingApprovals' => $approvals === null ? [] : (clone $approvals)
                ->with(['approvalRule', 'job.jobTemplate'])
                ->orderBy(['requested_at' => SORT_DESC])
                ->limit(5)
                ->all(),
            'runningWorkflows' => $runningWorkflows,
            'resumableWorkflowTemplateIds' => $this->resumableWorkflowTemplateIds($runningWorkflows),
            'upcomingSchedules' => $schedules === null ? [] : (clone $schedules)
                ->with(['jobTemplate'])
                ->andWhere(['>', 'next_run_at', 0])
                ->orderBy(['next_run_at' => SORT_ASC])
                ->limit(5)
                ->all(),
            'failedJobs' => $this->jobs($jobFilter)
                ->with(['jobTemplate', 'launcher'])
                ->andWhere(['status' => Job::terminalFailureStatuses()])
                ->andWhere(['>=', 'finished_at', $today - 86400])
                ->orderBy(['finished_at' => SORT_DESC])
                ->limit(5)
                ->all(),
            'syncErrors' => self::restrict(
                Project::find()->where(['status' => Project::STATUS_ERROR])->orderBy(['updated_at' => SORT_DESC]),
                $this->checker()->buildProjectFilter($this->currentUserId())
            )->all(),
            'hasSchedules' => $schedules !== null && $schedules->exists(),
        ]);
    }

    /**
     * @param array<int|string, mixed>|null $jobFilter
     * @return array{jobs_today: int, jobs_today_failed: int, queued: int, running: int, pending_approvals: int}
     */
    private function buildStatCards(int $today, ?array $jobFilter, ?ActiveQuery $approvals): array
    {
        return [
            'jobs_today' => (int)$this->jobs($jobFilter)->andWhere(['>=', 'created_at', $today])->count(),
            'jobs_today_failed' => (int)$this->jobs($jobFilter)
                ->andWhere(['>=', 'created_at', $today])
                ->andWhere(['status' => Job::terminalFailureStatuses()])
                ->count(),
            'queued' => (int)$this->jobs($jobFilter)->andWhere(['status' => Job::STATUS_QUEUED])->count(),
            'running' => (int)$this->jobs($jobFilter)->andWhere(['status' => Job::STATUS_RUNNING])->count(),
            'pending_approvals' => $approvals === null ? 0 : (int)(clone $approvals)->count(),
        ];
    }

    /**
     * @param array<int|string, mixed>|null $jobFilter
     * @return array<string, int>
     */
    private function buildStatusCounts(int $week, ?array $jobFilter): array
    {
        $statusCounts = [];
        foreach (Job::statuses() as $status) {
            $statusCounts[$status] = (int)$this->jobs($jobFilter)
                ->andWhere(['status' => $status])
                ->andWhere(['>=', 'created_at', $week])
                ->count();
        }
        return $statusCounts;
    }

    /**
     * Jobs the user may see.
     *
     * @param array<int|string, mixed>|null $jobFilter from ProjectAccessChecker::buildJobFilter()
     */
    private function jobs(?array $jobFilter): ActiveQuery
    {
        return self::restrict(Job::find(), $jobFilter);
    }

    /**
     * @param array<int|string, mixed>|null $filter null: no restriction
     */
    private static function restrict(ActiveQuery $query, ?array $filter): ActiveQuery
    {
        return $filter === null ? $query : $query->andWhere($filter);
    }

    /**
     * Pending approval requests the user may see; null without approval.view.
     */
    private function pendingApprovalQuery(): ?ActiveQuery
    {
        if (!$this->userMay('approval.view')) {
            return null;
        }

        return self::restrict(
            ApprovalRequest::find()->where(['status' => ApprovalRequest::STATUS_PENDING]),
            $this->workflowChecker()->buildApprovalRequestFilter($this->currentUserId())
        );
    }

    /**
     * Enabled schedules of job templates the user may see; null without the
     * permission the schedule pages require (job.launch).
     */
    private function scheduleQuery(): ?ActiveQuery
    {
        if (!$this->userMay('job.launch')) {
            return null;
        }

        return self::restrict(
            Schedule::find()->where(['enabled' => true]),
            $this->checker()->buildJobFilter($this->currentUserId(), 'schedule.job_template_id')
        );
    }

    /**
     * Quick launch: the job templates the user may launch.
     *
     * @return JobTemplate[]
     */
    private function quickLaunchTemplates(): array
    {
        if (!$this->userMay('job.launch')) {
            return [];
        }
        /** @var JobTemplate[] $templates */
        $templates = self::restrict(
            JobTemplate::find()->select(['id', 'name'])->orderBy('name'),
            $this->checker()->buildChildOperateFilter($this->currentUserId(), 'job_template.project_id')
        )->all();

        return $templates;
    }

    /**
     * Quick launch: the workflow templates whose every job step the user may
     * launch.
     *
     * @return WorkflowTemplate[]
     */
    private function quickLaunchWorkflows(): array
    {
        if (!$this->userMay('workflow.launch')) {
            return [];
        }
        /** @var WorkflowTemplate[] $workflows */
        $workflows = self::restrict(
            WorkflowTemplate::find()->select(['id', 'name'])->orderBy('name'),
            $this->workflowChecker()->buildWorkflowTemplateFilter($this->currentUserId(), 'workflow_template.id', true)
        )->all();

        return $workflows;
    }

    /**
     * Running workflows whose every job step the user may see.
     *
     * @return WorkflowJob[]
     */
    private function runningWorkflows(): array
    {
        if (!$this->userMay('workflow.view')) {
            return [];
        }
        /** @var WorkflowJob[] $running */
        $running = self::restrict(
            WorkflowJob::find()
                ->with(['workflowTemplate', 'launcher', 'currentStep'])
                ->where(['status' => WorkflowJob::STATUS_RUNNING])
                ->orderBy(['id' => SORT_DESC]),
            $this->workflowChecker()->buildWorkflowTemplateFilter($this->currentUserId(), 'workflow_job.workflow_template_id')
        )->all();

        return $running;
    }

    /**
     * The workflow templates among $running whose paused runs the user may
     * resume: workflow.launch and operator access to every job step.
     *
     * @param WorkflowJob[] $running
     * @return list<int>
     */
    private function resumableWorkflowTemplateIds(array $running): array
    {
        $userId = $this->currentUserId();
        if ($userId === null || $running === [] || !$this->userMay('workflow.launch')) {
            return [];
        }
        $ids = array_unique(array_map(static fn (WorkflowJob $wf): int => (int)$wf->workflow_template_id, $running));
        $access = $this->workflowChecker();

        return array_values(array_filter(
            $ids,
            static fn (int $id): bool => $access->canOperateWorkflowTemplate($userId, $id)
        ));
    }

    private function countOutdatedRunners(): int
    {
        $outdated = 0;
        foreach (Runner::find()->all() as $runner) {
            if ($runner->isOutdated()) {
                $outdated++;
            }
        }
        return $outdated;
    }

    /**
     * Superadmins hold every permission, as in the access rules.
     */
    private function userMay(string $permission): bool
    {
        $identity = \Yii::$app->user->identity;
        if ($identity instanceof User && (bool)$identity->is_superadmin) {
            return true;
        }

        return \Yii::$app->user->can($permission);
    }

    /**
     * GET /site/chart-data?days=30
     * Returns daily aggregated job outcomes + host recap totals for dashboard
     * charts, counting only jobs the user may see.
     */
    public function actionChartData(int $days = 30): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $days = max(7, min(365, $days));
        $today = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $userId = $this->currentUserId();
        $jobFilter = $this->checker()->buildJobFilter($userId);
        $recapScope = $this->recapScope($this->checker()->buildJobFilter($userId, 'j.job_template_id'));

        $labels = [];
        $jobOk = [];
        $jobFailed = [];
        $taskOk = [];
        $taskChanged = [];
        $taskFailed = [];
        $taskUnreach = [];
        $taskSkipped = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $dayStart = (int)strtotime("-{$i} days", $today);
            $dayEnd = $dayStart + 86399;

            $labels[] = date('d.m.', $dayStart);

            // Job outcomes
            $finished = ['between', 'finished_at', $dayStart, $dayEnd];
            $jobOk[] = (int)$this->jobs($jobFilter)->andWhere(['status' => Job::STATUS_SUCCEEDED])->andWhere($finished)->count();
            $jobFailed[] = (int)$this->jobs($jobFilter)->andWhere(['status' => Job::terminalFailureStatuses()])->andWhere($finished)->count();

            $row = $this->recapTotals($recapScope, $dayStart, $dayEnd);
            $taskOk[] = $row['ok'];
            $taskChanged[] = $row['changed'];
            $taskFailed[] = $row['failed'];
            $taskUnreach[] = $row['unreachable'];
            $taskSkipped[] = $row['skipped'];
        }

        return $this->asJson([
            'labels' => $labels,
            'jobs' => ['ok' => $jobOk, 'failed' => $jobFailed],
            'tasks' => ['ok' => $taskOk, 'changed' => $taskChanged, 'failed' => $taskFailed, 'unreachable' => $taskUnreach, 'skipped' => $taskSkipped],
        ]);
    }

    /**
     * The job filter as SQL for the host recap query, with its parameters.
     *
     * @param array<int|string, mixed>|null $jobFilter on j.job_template_id; null: no restriction
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function recapScope(?array $jobFilter): array
    {
        if ($jobFilter === null) {
            return ['sql' => '', 'params' => []];
        }
        $params = [];
        $sql = \Yii::$app->db->getQueryBuilder()->buildCondition($jobFilter, $params);

        return ['sql' => ' AND ' . $sql, 'params' => $params];
    }

    /**
     * Host recap sums of the jobs that finished in the given period: join
     * job_host_summary to job for finished_at.
     *
     * @param array{sql: string, params: array<string, mixed>} $scope
     * @return array{ok: int, changed: int, failed: int, unreachable: int, skipped: int}
     */
    private function recapTotals(array $scope, int $dayStart, int $dayEnd): array
    {
        $sql = 'SELECT'
            . ' COALESCE(SUM(s.ok), 0) AS ok,'
            . ' COALESCE(SUM(s.changed), 0) AS changed,'
            . ' COALESCE(SUM(s.failed), 0) AS failed,'
            . ' COALESCE(SUM(s.unreachable), 0) AS unreachable,'
            . ' COALESCE(SUM(s.skipped), 0) AS skipped'
            . ' FROM {{%job_host_summary}} s'
            . ' JOIN {{%job}} j ON j.id = s.job_id'
            . ' WHERE j.finished_at BETWEEN :ds AND :de' . $scope['sql'];
        $params = array_merge($scope['params'], [':ds' => $dayStart, ':de' => $dayEnd]);
        $row = \Yii::$app->db->createCommand($sql, $params)->queryOne();
        $row = is_array($row) ? $row : [];

        return [
            'ok' => (int)($row['ok'] ?? 0),
            'changed' => (int)($row['changed'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'unreachable' => (int)($row['unreachable'] ?? 0),
            'skipped' => (int)($row['skipped'] ?? 0),
        ];
    }

    public function actionLogin(): Response|string
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }
        $model = new LoginForm();
        if ($model->load((array)\Yii::$app->request->post()) && $model->validateCredentials()) {
            // Credentials valid — check if TOTP is required
            if ($model->requiresTotp()) {
                // Store pending login in session, redirect to TOTP step
                /** @var \app\models\User $user Validated above */
                $user = $model->getUserModel();
                /** @var \yii\web\Session $session */
                $session = \Yii::$app->session;
                $session->set('totp_pending_user_id', $user->id);
                $session->set('totp_pending_remember', $model->rememberMe);
                return $this->redirect(['verify-totp']);
            }

            // No TOTP — log in directly
            if ($model->login()) {
                // Regenerate session ID to prevent session fixation
                /** @var \yii\web\Session $session */
                $session = \Yii::$app->session;
                $session->regenerateID(true);

                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_USER_LOGIN,
                    null,
                    null,
                    null,
                    ['username' => $model->username]
                );
                return $this->goBack();
            }
        }
        if ($model->hasErrors()) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_USER_LOGIN_FAILED,
                null,
                null,
                null,
                ['username' => $model->username]
            );
        }
        $model->password = '';
        return $this->render('login', ['model' => $model]);
    }

    public function actionVerifyTotp(): Response|string
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;

        /** @var int|null $userId */
        $userId = $session->get('totp_pending_user_id');
        if (empty($userId)) {
            return $this->redirect(['login']);
        }

        $user = User::findIdentity($userId);
        if ($user === null || !$user->totp_enabled) {
            $session->remove('totp_pending_user_id');
            $session->remove('totp_pending_remember');
            return $this->redirect(['login']);
        }

        $model = new TotpVerifyForm($user);
        if ($model->load((array)\Yii::$app->request->post()) && $model->validate()) {
            $remember = (bool)$session->get('totp_pending_remember', false);
            $duration = $remember ? 3600 * 24 * 30 : 0;

            // Clear pending state before login
            $session->remove('totp_pending_user_id');
            $session->remove('totp_pending_remember');

            /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
            $userComponent = \Yii::$app->user;
            $userComponent->login($user, $duration);

            // Regenerate session ID to prevent fixation
            $session->regenerateID(true);

            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_USER_LOGIN,
                null,
                null,
                null,
                [
                    'username' => $user->username,
                    'mfa' => true,
                    'recovery_code' => $model->usedRecoveryCode(),
                ]
            );

            if ($model->usedRecoveryCode()) {
                /** @var \app\services\TotpService $totp */
                $totp = \Yii::$app->get('totpService');
                $remaining = $totp->remainingRecoveryCodeCount($user);
                $this->session()->setFlash('warning', "You used a recovery code to log in. You have {$remaining} recovery codes remaining.");
            }

            return $this->goBack();
        }

        return $this->render('verify-totp', ['model' => $model]);
    }

    public function actionForgotPassword(): Response|string
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new PasswordResetRequestForm();
        if ($model->load((array)\Yii::$app->request->post()) && $model->validate()) {
            $model->sendResetEmail();
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_PASSWORD_RESET_REQUESTED,
                null,
                null,
                null,
                ['email' => $model->email]
            );
            $this->session()->setFlash('success', 'If an account with that email exists, a password reset link has been sent.');
            return $this->redirect(['login']);
        }

        return $this->render('forgot-password', ['model' => $model]);
    }

    public function actionResetPassword(string $token = ''): Response|string
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        if (empty($token)) {
            throw new BadRequestHttpException('Missing password reset token.');
        }

        try {
            $model = new PasswordResetForm($token);
        } catch (\yii\base\InvalidArgumentException) {
            $this->session()->setFlash('danger', 'Invalid or expired password reset link. Please request a new one.');
            return $this->redirect(['forgot-password']);
        }

        if ($model->load((array)\Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_PASSWORD_RESET_COMPLETED,
                'user',
                $model->getUser()->id
            );
            $this->session()->setFlash('success', 'Your password has been reset. You can now log in.');
            return $this->redirect(['login']);
        }

        return $this->render('reset-password', ['model' => $model]);
    }

    public function actionLogout(): Response
    {
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_LOGOUT);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->logout();
        return $this->goHome();
    }
}

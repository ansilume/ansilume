<?php

declare(strict_types=1);

namespace app\controllers;

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
use app\models\TotpVerifyForm;
use app\models\User;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SiteController extends BaseController
{
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

    public function actionIndex(): string
    {
        $today = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $week = strtotime('-6 days', $today);

        $stats = [
            'projects' => Project::find()->count(),
            'queued' => Job::find()->where(['status' => Job::STATUS_QUEUED])->count(),
            'running' => Job::find()->where(['status' => Job::STATUS_RUNNING])->count(),
            'jobs_today' => Job::find()->where(['>=', 'created_at', $today])->count(),
        ];

        // Status breakdown for the last 7 days
        $statusCounts = [];
        foreach (Job::statuses() as $status) {
            $statusCounts[$status] = (int)Job::find()
                ->where(['status' => $status])
                ->andWhere(['>=', 'created_at', $week])
                ->count();
        }

        $recentJobs = Job::find()
            ->with(['jobTemplate', 'launcher'])
            ->orderBy(['id' => SORT_DESC])
            ->limit(10)
            ->all();

        $runningJobs = Job::find()
            ->with(['jobTemplate', 'launcher'])
            ->where(['status' => Job::STATUS_RUNNING])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $templates = JobTemplate::find()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->all();

        $cutoff = time() - RunnerGroup::STALE_AFTER;
        $totalRunners = (int)Runner::find()->count();
        $onlineRunners = (int)Runner::find()->where(['>=', 'last_seen_at', $cutoff])->count();

        return $this->render('index', [
            'stats' => $stats,
            'statusCounts' => $statusCounts,
            'recentJobs' => $recentJobs,
            'runningJobs' => $runningJobs,
            'templates' => $templates,
            'onlineRunners' => $onlineRunners,
            'totalRunners' => $totalRunners,
        ]);
    }

    /**
     * GET /site/chart-data?days=30
     * Returns daily aggregated job outcomes + host recap totals for dashboard charts.
     */
    public function actionChartData(int $days = 30): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $days = max(7, min(365, $days));
        $today = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));

        $labels = [];
        $jobOk = [];
        $jobFailed = [];
        $taskOk = [];
        $taskChanged = [];
        $taskFailed = [];
        $taskUnreach = [];
        $taskSkipped = [];

        $db = \Yii::$app->db;

        for ($i = $days - 1; $i >= 0; $i--) {
            $dayStart = strtotime("-{$i} days", $today);
            $dayEnd = $dayStart + 86399;

            $labels[] = date('d.m.', $dayStart);

            // Job outcomes
            $jobOk[] = (int)Job::find()->where(['status' => Job::STATUS_SUCCEEDED])->andWhere(['between', 'finished_at', $dayStart, $dayEnd])->count();
            $jobFailed[] = (int)Job::find()->where(['status' => Job::STATUS_FAILED])->andWhere(['between', 'finished_at', $dayStart, $dayEnd])->count();

            // Host recap sums: join job_host_summary → job to get finished_at date
            $row = $db->createCommand('
                SELECT
                    COALESCE(SUM(s.ok), 0)          AS ok,
                    COALESCE(SUM(s.changed), 0)     AS changed,
                    COALESCE(SUM(s.failed), 0)      AS failed,
                    COALESCE(SUM(s.unreachable), 0) AS unreachable,
                    COALESCE(SUM(s.skipped), 0)     AS skipped
                FROM {{%job_host_summary}} s
                JOIN {{%job}} j ON j.id = s.job_id
                WHERE j.finished_at BETWEEN :ds AND :de
            ', [':ds' => $dayStart, ':de' => $dayEnd])->queryOne();

            $taskOk[] = (int)($row['ok'] ?? 0);
            $taskChanged[] = (int)($row['changed'] ?? 0);
            $taskFailed[] = (int)($row['failed'] ?? 0);
            $taskUnreach[] = (int)($row['unreachable'] ?? 0);
            $taskSkipped[] = (int)($row['skipped'] ?? 0);
        }

        return $this->asJson([
            'labels' => $labels,
            'jobs' => ['ok' => $jobOk, 'failed' => $jobFailed],
            'tasks' => ['ok' => $taskOk, 'changed' => $taskChanged, 'failed' => $taskFailed, 'unreachable' => $taskUnreach, 'skipped' => $taskSkipped],
        ]);
    }

    public function actionLogin(): Response|string
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }
        $model = new LoginForm();
        if ($model->load(\Yii::$app->request->post()) && $model->validateCredentials()) {
            // Credentials valid — check if TOTP is required
            if ($model->requiresTotp()) {
                // Store pending login in session, redirect to TOTP step
                $user = $model->getUserModel();
                /** @var \yii\web\Session $session */
                $session = \Yii::$app->session;
                $session->set('totp_pending_user_id', $user->id);
                $session->set('totp_pending_remember', $model->rememberMe);
                return $this->redirect(['verify-totp']);
            }

            // No TOTP — log in directly
            if ($model->login()) {
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
        if ($model->load(\Yii::$app->request->post()) && $model->validate()) {
            $remember = (bool)$session->get('totp_pending_remember', false);
            $duration = $remember ? 3600 * 24 * 30 : 0;

            // Clear pending state before login
            $session->remove('totp_pending_user_id');
            $session->remove('totp_pending_remember');

            \Yii::$app->user->login($user, $duration);

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
        if ($model->load(\Yii::$app->request->post()) && $model->validate()) {
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

        if ($model->load(\Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
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
        \Yii::$app->user->logout();
        return $this->goHome();
    }
}

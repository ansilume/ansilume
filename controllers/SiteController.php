<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\WorkerHeartbeat;
use app\models\Job;
use app\models\JobTemplate;
use app\models\LoginForm;
use app\models\Project;
use app\services\AuditService;
use yii\web\Response;

class SiteController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['login', 'error'], 'allow' => true],
            ['actions' => ['index', 'logout'], 'allow' => true, 'roles' => ['@']],
        ];
    }

    public function actions(): array
    {
        return ['error' => ['class' => 'yii\web\ErrorAction']];
    }

    public function actionIndex(): string
    {
        $today = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $week  = strtotime('-6 days', $today);

        $stats = [
            'projects'   => Project::find()->count(),
            'templates'  => JobTemplate::find()->count(),
            'running'    => Job::find()->where(['status' => Job::STATUS_RUNNING])->count(),
            'jobs_today' => Job::find()->where(['>=', 'created_at', $today])->count(),
        ];

        // Per-day job counts for the last 7 days (for the sparkline chart)
        $dailyCounts = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = strtotime("-{$i} days", $today);
            $dayEnd   = $dayStart + 86399;
            $dailyCounts[] = [
                'date'      => date('D', $dayStart),
                'succeeded' => (int)Job::find()->where(['status' => Job::STATUS_SUCCEEDED])->andWhere(['between', 'created_at', $dayStart, $dayEnd])->count(),
                'failed'    => (int)Job::find()->where(['status' => Job::STATUS_FAILED])->andWhere(['between', 'created_at', $dayStart, $dayEnd])->count(),
            ];
        }

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

        $workers = WorkerHeartbeat::all();
        $now     = time();
        $aliveWorkers = array_filter($workers, fn($w) => ($now - ($w['seen_at'] ?? 0)) < WorkerHeartbeat::STALE_AFTER);

        return $this->render('index', [
            'stats'        => $stats,
            'dailyCounts'  => $dailyCounts,
            'statusCounts' => $statusCounts,
            'recentJobs'   => $recentJobs,
            'runningJobs'  => $runningJobs,
            'templates'    => $templates,
            'workerCount'  => count($aliveWorkers),
        ]);
    }

    public function actionLogin(): Response|string
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }
        $model = new LoginForm();
        if ($model->load(\Yii::$app->request->post()) && $model->login()) {
            \Yii::$app->get('auditService')->log(
                AuditService::ACTION_USER_LOGIN, null, null, null, ['username' => $model->username]
            );
            return $this->goBack();
        }
        if ($model->hasErrors()) {
            \Yii::$app->get('auditService')->log(
                AuditService::ACTION_USER_LOGIN_FAILED, null, null, null, ['username' => $model->username]
            );
        }
        $model->password = '';
        return $this->render('login', ['model' => $model]);
    }

    public function actionLogout(): Response
    {
        \Yii::$app->get('auditService')->log(AuditService::ACTION_USER_LOGOUT);
        \Yii::$app->user->logout();
        return $this->goHome();
    }
}

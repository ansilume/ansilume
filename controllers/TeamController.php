<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Project;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use yii\data\ActiveDataProvider;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class TeamController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],         'allow' => true, 'roles' => ['admin']],
            ['actions' => ['create', 'update'],       'allow' => true, 'roles' => ['admin']],
            ['actions' => ['delete'],                 'allow' => true, 'roles' => ['admin']],
            ['actions' => ['add-member', 'remove-member',
                           'add-project', 'remove-project'], 'allow' => true, 'roles' => ['admin']],
        ];
    }

    protected function verbRules(): array
    {
        return [
            'delete'         => ['POST'],
            'add-member'     => ['POST'],
            'remove-member'  => ['POST'],
            'add-project'    => ['POST'],
            'remove-project' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Team::find()->with(['creator'])->orderBy(['name' => SORT_ASC]),
            'pagination' => ['pageSize' => 25],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $team     = $this->findModel($id);
        $allUsers = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->andWhere(['NOT IN', 'id', array_map(fn($m) => $m->user_id, $team->teamMembers)])
            ->orderBy('username')
            ->all();
        $allProjects = Project::find()
            ->andWhere(['NOT IN', 'id', array_map(fn($tp) => $tp->project_id, $team->teamProjects)])
            ->orderBy('name')
            ->all();

        return $this->render('view', [
            'team'        => $team,
            'allUsers'    => $allUsers,
            'allProjects' => $allProjects,
        ]);
    }

    public function actionCreate(): Response|string
    {
        $model = new Team();
        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEAM_CREATED, 'team', $model->id, null, ['name' => $model->name]);
                $this->session()->setFlash('success', "Team \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load(\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEAM_UPDATED, 'team', $model->id, null, ['name' => $model->name]);
            $this->session()->setFlash('success', "Team \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name  = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEAM_DELETED, 'team', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', "Team \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionAddMember(int $id): Response
    {
        $team   = $this->findModel($id);
        $userId = (int)\Yii::$app->request->post('user_id');
        if (!$userId) {
            throw new BadRequestHttpException('user_id required.');
        }
        $member          = new TeamMember();
        $member->team_id = $team->id;
        $member->user_id = $userId;
        $member->created_at = time();
        if (!$member->save()) {
            $this->session()->setFlash('danger', 'Could not add member: ' . json_encode($member->errors));
        } else {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEAM_MEMBER_ADDED, 'team', $team->id, null, ['user_id' => $userId]);
            $this->session()->setFlash('success', 'Member added.');
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRemoveMember(int $id, int $userId): Response
    {
        TeamMember::deleteAll(['team_id' => $id, 'user_id' => $userId]);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEAM_MEMBER_REMOVED, 'team', $id, null, ['user_id' => $userId]);
        $this->session()->setFlash('success', 'Member removed.');
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionAddProject(int $id): Response
    {
        $team      = $this->findModel($id);
        $projectId = (int)\Yii::$app->request->post('project_id');
        $role      = \Yii::$app->request->post('role', TeamProject::ROLE_VIEWER);
        if (!$projectId) {
            throw new BadRequestHttpException('project_id required.');
        }
        if (!in_array($role, [TeamProject::ROLE_VIEWER, TeamProject::ROLE_OPERATOR], true)) {
            $role = TeamProject::ROLE_VIEWER;
        }
        $tp             = new TeamProject();
        $tp->team_id    = $team->id;
        $tp->project_id = $projectId;
        $tp->role       = $role;
        $tp->created_at = time();
        if (!$tp->save()) {
            $this->session()->setFlash('danger', 'Could not add project: ' . json_encode($tp->errors));
        } else {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEAM_PROJECT_ADDED, 'team', $team->id, null, ['project_id' => $projectId, 'role' => $role]);
            $this->session()->setFlash('success', 'Project access granted.');
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRemoveProject(int $id, int $projectId): Response
    {
        TeamProject::deleteAll(['team_id' => $id, 'project_id' => $projectId]);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEAM_PROJECT_REMOVED, 'team', $id, null, ['project_id' => $projectId]);
        $this->session()->setFlash('success', 'Project access removed.');
        return $this->redirect(['view', 'id' => $id]);
    }

    private function findModel(int $id): Team
    {
        $model = Team::find()
            ->with(['teamMembers.user', 'teamProjects.project'])
            ->where(['id' => $id])
            ->one();
        if ($model === null) {
            throw new NotFoundHttpException("Team #{$id} not found.");
        }
        return $model;
    }
}

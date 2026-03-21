<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\Project;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Projects
 *
 * GET /api/v1/projects
 * GET /api/v1/projects/{id}
 */
class ProjectsController extends BaseApiController
{
    public function actionIndex(): array
    {
        $dp   = new ActiveDataProvider([
            'query' => Project::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = (int)\Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn($p) => $this->serialize($p), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    public function actionView(int $id): array
    {
        $project = Project::findOne($id);
        if ($project === null) {
            throw new NotFoundHttpException("Project #{$id} not found.");
        }
        return $this->success($this->serialize($project));
    }

    private function serialize(Project $p): array
    {
        return [
            'id'             => $p->id,
            'name'           => $p->name,
            'description'    => $p->description,
            'scm_type'       => $p->scm_type,
            'scm_url'        => $p->scm_url,
            'scm_branch'     => $p->scm_branch,
            'status'         => $p->status,
            'last_synced_at' => $p->last_synced_at,
            'created_at'     => $p->created_at,
        ];
    }
}

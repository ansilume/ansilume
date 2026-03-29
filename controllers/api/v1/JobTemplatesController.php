<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\JobTemplate;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Job Templates
 *
 * GET /api/v1/job-templates
 * GET /api/v1/job-templates/{id}
 */
class JobTemplatesController extends BaseApiController
{
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => JobTemplate::find()->with(['project', 'inventory'])->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = (int)\Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($t) => $this->serialize($t), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    public function actionView(int $id): array
    {
        $template = JobTemplate::findOne($id);
        if ($template === null) {
            throw new NotFoundHttpException("Template #{$id} not found.");
        }
        return $this->success($this->serialize($template));
    }

    private function serialize(JobTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'project_id' => $t->project_id,
            'project_name' => $t->project->name ?? null,
            'inventory_id' => $t->inventory_id,
            'inventory_name' => $t->inventory->name ?? null,
            'credential_id' => $t->credential_id,
            'playbook' => $t->playbook,
            'verbosity' => $t->verbosity,
            'forks' => $t->forks,
            'become' => (bool)$t->become,
            'become_method' => $t->become_method,
            'become_user' => $t->become_user,
            'limit' => $t->limit,
            'tags' => $t->tags,
            'skip_tags' => $t->skip_tags,
            'has_survey' => $t->hasSurvey(),
            'notify_on_failure' => (bool)$t->notify_on_failure,
            'notify_on_success' => (bool)$t->notify_on_success,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ];
    }
}

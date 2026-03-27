<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\Credential;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Credentials (read-only, secrets never returned)
 *
 * GET /api/v1/credentials
 * GET /api/v1/credentials/{id}
 */
class CredentialsController extends BaseApiController
{
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => Credential::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = (int)\Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn($c) => $this->serialize($c), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    public function actionView(int $id): array
    {
        $credential = Credential::findOne($id);
        if ($credential === null) {
            throw new NotFoundHttpException("Credential #{$id} not found.");
        }
        return $this->success($this->serialize($credential));
    }

    private function serialize(Credential $c): array
    {
        // secret_data is NEVER included — not even a redacted placeholder.
        // The API caller only needs to know the credential exists and its type.
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'credential_type' => $c->credential_type,
            'username' => $c->username,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }
}

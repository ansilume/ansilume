<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApiToken;
use yii\filters\AccessControl;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Lets the logged-in user manage their own API tokens.
 */
class ProfileController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['tokens', 'create-token', 'delete-token'], 'allow' => true, 'roles' => ['@']],
        ];
    }

    protected function verbRules(): array
    {
        return ['create-token' => ['POST'], 'delete-token' => ['POST']];
    }

    public function actionTokens(): string
    {
        $userId = (int)\Yii::$app->user->id;
        $tokens = ApiToken::find()
            ->where(['user_id' => $userId])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $newToken = \Yii::$app->session->getFlash('new_token');
        return $this->render('tokens', ['tokens' => $tokens, 'newToken' => $newToken]);
    }

    public function actionCreateToken(): Response
    {
        $name = trim((string)\Yii::$app->request->post('name', ''));
        if ($name === '') {
            \Yii::$app->session->setFlash('danger', 'Token name is required.');
            return $this->redirect(['tokens']);
        }

        ['raw' => $raw] = ApiToken::generate((int)\Yii::$app->user->id, $name);

        // Store raw token in session flash — displayed once, never again
        \Yii::$app->session->setFlash('new_token', $raw);
        \Yii::$app->session->setFlash('success', 'Token created. Copy it now — it will not be shown again.');
        return $this->redirect(['tokens']);
    }

    public function actionDeleteToken(int $id): Response
    {
        $token = ApiToken::findOne(['id' => $id, 'user_id' => (int)\Yii::$app->user->id]);
        if ($token === null) {
            throw new NotFoundHttpException('Token not found.');
        }
        $token->delete();
        \Yii::$app->session->setFlash('success', "Token "{$token->name}" revoked.");
        return $this->redirect(['tokens']);
    }
}

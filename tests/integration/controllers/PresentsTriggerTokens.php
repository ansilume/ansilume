<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\TriggerTokenResolver;

/**
 * Puts a trigger token into the current test request the way an external
 * caller sends it: in the X-Trigger-Token header, in the URL (the query
 * parameter, which also carries the path form /trigger/<token>), or as
 * `Authorization: Bearer <token>`. Use in WebControllerTestCase subclasses.
 */
trait PresentsTriggerTokens
{
    protected function presentTriggerToken(string $token, string $transport = TriggerTokenResolver::TRANSPORT_HEADER): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        if ($transport === TriggerTokenResolver::TRANSPORT_HEADER) {
            $request->getHeaders()->set(TriggerTokenResolver::HEADER, $token);
        } elseif ($transport === TriggerTokenResolver::TRANSPORT_BEARER) {
            $request->getHeaders()->set('Authorization', 'Bearer ' . $token);
        } else {
            $request->setQueryParams(['token' => $token] + $request->getQueryParams());
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function transportProvider(): array
    {
        return [
            'X-Trigger-Token header' => [TriggerTokenResolver::TRANSPORT_HEADER],
            'URL (deprecated)' => [TriggerTokenResolver::TRANSPORT_URL],
            'Authorization: Bearer' => [TriggerTokenResolver::TRANSPORT_BEARER],
        ];
    }
}

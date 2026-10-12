<?php

declare(strict_types=1);

// The yii2-debug module, loaded only with YII_ENV dev (config/web.php). Its
// request panel saves every request's headers, $_SERVER and session to
// runtime/debug/; trigger tokens and API tokens are censored there.
return [
    'class' => 'yii\debug\Module',
    'allowedIPs' => ['*'],
    'panels' => [
        'request' => [
            'class' => 'yii\debug\panels\RequestPanel',
            'censoredVariableNames' => [
                // Request headers, named in lower case as the panel lists them.
                'x-trigger-token',
                'authorization',
                // The same headers in the saved $_SERVER.
                'SERVER.HTTP_X_TRIGGER_TOKEN',
                'SERVER.HTTP_AUTHORIZATION',
                // A newly generated trigger token, kept in a flash for the next page.
                'flashes.trigger_token_raw',
                'SESSION.trigger_token_raw',
            ],
        ],
    ],
];

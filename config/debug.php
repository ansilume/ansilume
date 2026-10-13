<?php

declare(strict_types=1);

// The yii2-debug module, loaded only with YII_ENV dev (config/web.php). Its
// request panel saves every request's headers, $_SERVER, flashes and session
// to runtime/debug/; trigger tokens, API tokens, runner tokens and a TOTP
// secret being set up are censored there.
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
                // The data flashes, which carry a new token to the next page
                // (tests/unit/controllers/FlashTypesTest.php lists them), as
                // the panel saves them: among the flashes and in the session.
                // A new trigger token:
                'flashes.trigger_token_raw',
                'SESSION.trigger_token_raw',
                // The token of a new runner, or a regenerated one:
                'flashes.runner_token',
                'SESSION.runner_token',
                // A new API token:
                'flashes.new_token',
                'SESSION.new_token',
                // The TOTP secret while two-factor authentication is set up
                // (ProfileController keeps it in the session until it is enabled).
                'SESSION.totp_setup_secret',
            ],
        ],
    ],
];

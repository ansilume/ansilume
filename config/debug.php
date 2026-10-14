<?php

declare(strict_types=1);

// The yii2-debug module, loaded only with YII_ENV dev (config/web.php), and
// reachable by everyone who reaches the stack: a dev stack must never be
// reachable by others. Its request panel saves every request's headers,
// body, $_POST, $_COOKIE, $_SERVER, flashes and session to runtime/debug/;
// what carries a secret is censored there (tests/unit/config/
// DebugCensorTest.php, DebugModuleConfigTest.php).
//
// A name is a request header in lower case, a response header as PHP sends
// it, or a path into what the panel saves: requestBody (with its Raw and
// Decoded parts), POST.<form>.<field>, COOKIE, SERVER.<name>,
// SESSION.<key>, GET.<param>, actionParams.<param>, flashes.<key>.
//
// The URL itself (the request list, SERVER.REQUEST_URI) still names a token
// sent in it: a deprecated trigger URL or a password reset link.
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
                // The session and remember-me cookies, in every form the
                // panel saves them; the response sets them on a sign-in.
                'cookie',
                'Set-Cookie',
                'COOKIE',
                'SERVER.HTTP_COOKIE',
                // The token headers and the password of HTTP basic auth in
                // the saved $_SERVER.
                'SERVER.HTTP_X_TRIGGER_TOKEN',
                'SERVER.HTTP_AUTHORIZATION',
                'SERVER.PHP_AUTH_PW',
                // Every secret of .env, which phpdotenv writes into $_SERVER
                // (DebugCensorTest checks the list against .env.example).
                'SERVER.APP_SECRET_KEY',
                'SERVER.COOKIE_VALIDATION_KEY',
                'SERVER.DB_PASSWORD',
                'SERVER.DB_ROOT_PASSWORD',
                'SERVER.LDAP_BIND_PASSWORD',
                'SERVER.REDIS_PASSWORD',
                'SERVER.RUNNER_BOOTSTRAP_SECRET',
                'SERVER.SMTP_PASSWORD',
                // The request body as sent and as parsed: it cannot be
                // censored in part, and it holds every field below.
                'requestBody',
                // The fields of the web forms that carry a secret.
                'POST.LoginForm.password',
                'POST.UserForm.password',
                'POST.PasswordResetForm.password',
                'POST.PasswordResetForm.password_confirm',
                'POST.ChangePasswordForm.current_password',
                'POST.ChangePasswordForm.new_password',
                'POST.ChangePasswordForm.new_password_confirm',
                // A TOTP code may be a recovery code.
                'POST.TotpVerifyForm.code',
                'POST.TotpSetupForm.code',
                'POST.TotpDisableForm.code',
                // Credential secrets, and the survey answers of a launch.
                'POST.secrets',
                'POST.survey',
                // Extra vars that may hold password answers.
                'POST.overrides.extra_vars',
                'POST.Schedule.extra_vars',
                // Channel secrets, the webhook signing secret, and the
                // credentials a repository URL may hold.
                'POST.NotificationTemplate.config',
                'POST.Webhook.secret',
                'POST.Project.scm_url',
                // The same fields of an API request sent form-encoded, and
                // the runner bootstrap secret.
                'POST.password',
                'POST.secret',
                'POST.config',
                'POST.scm_url',
                'POST.extra_vars',
                'POST.bootstrap_secret',
                // A token sent in the URL: the trigger URL forms
                // (?token=, /trigger/<token>) and the password reset link.
                'GET.token',
                'actionParams.token',
                // The identity's auth key, which the remember-me cookie carries.
                'SESSION.__authKey',
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

<?php

// Auto-login for dev — injects POST credentials before Adminer processes them.
// Never use this in production.

if (empty($_POST['auth']) && empty($_GET['server'])) {
    $_POST['auth'] = [
        'driver'   => 'server',
        'server'   => getenv('DB_HOST') ?: 'db',
        'username' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'db'       => getenv('DB_NAME') ?: '',
    ];
}

function adminer_object()
{
    class AdminerAutoLogin extends Adminer
    {
        public function login($login, $password): bool
        {
            return true;
        }
    }
    return new AdminerAutoLogin();
}

include './adminer.php';

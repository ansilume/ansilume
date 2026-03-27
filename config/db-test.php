<?php

declare(strict_types=1);

return [
    'class' => 'yii\db\Connection',
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        $_ENV['DB_HOST'] ?? 'db',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_TEST_NAME'] ?? 'ansilume_test'
    ),
    'username' => $_ENV['DB_USER'] ?? 'ansilume',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
];

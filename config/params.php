<?php

declare(strict_types=1);

return [
    'adminEmail'         => $_ENV['ADMIN_EMAIL'] ?? 'admin@example.com',
    'senderEmail'        => $_ENV['SENDER_EMAIL'] ?? 'noreply@example.com',
    'senderName'         => 'Ansilume',
    'jobWorkspacePath'   => $_ENV['JOB_WORKSPACE_PATH'] ?? '/tmp/ansilume/jobs',
    'jobLogPath'         => $_ENV['JOB_LOG_PATH']       ?? '/var/www/runtime/job-logs',
];

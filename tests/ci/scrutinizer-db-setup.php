<?php

declare(strict_types=1);

/*
 * Scrutinizer build step: waits for the database service, creates the
 * databases and the application user, and writes the host that answered
 * into .env.
 *
 * Every attempt is bounded. mysqlnd waits up to mysqlnd.net_read_timeout
 * (default one day) for the server greeting, and neither PDO::ATTR_TIMEOUT
 * nor default_socket_timeout shortens that. A service that accepts
 * connections but never answers kept the old wait loop busy for hours.
 * Diagnostics go to stderr, so the build log shows what was listening.
 *
 * Settings (environment): SCRUTINIZER_DB_HOSTS (comma-separated),
 * SCRUTINIZER_DB_PORT, SCRUTINIZER_DB_DEADLINE and
 * SCRUTINIZER_DB_ATTEMPT_TIMEOUT (seconds), SCRUTINIZER_DB_ROOT_PASSWORD,
 * SCRUTINIZER_ENV_FILE.
 */

$setting = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};
$say = static function (string $line): void {
    fwrite(STDERR, $line . PHP_EOL);
};

$hostList = $setting('SCRUTINIZER_DB_HOSTS', '127.0.0.1,mariadb,mysql,localhost');
$hosts = array_values(array_filter(array_map('trim', explode(',', $hostList))));
$port = (int)$setting('SCRUTINIZER_DB_PORT', '3306');
$deadline = time() + (int)$setting('SCRUTINIZER_DB_DEADLINE', '180');
$rootPassword = (string)getenv('SCRUTINIZER_DB_ROOT_PASSWORD');
$envFile = $setting('SCRUTINIZER_ENV_FILE', '.env');
$attemptTimeout = max(1, (int)$setting('SCRUTINIZER_DB_ATTEMPT_TIMEOUT', '5'));

ini_set('mysqlnd.net_read_timeout', (string)$attemptTimeout);
ini_set('default_socket_timeout', (string)$attemptTimeout);

// What answers on the port at all, without speaking MySQL.
$probe = static function (string $host, int $port, string $send) use ($attemptTimeout): string {
    $error = '';
    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
        $error = $message;
        return true;
    });
    $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $attemptTimeout);
    restore_error_handler();
    if ($socket === false) {
        return "no connection: {$errstr} {$error}";
    }
    stream_set_timeout($socket, $attemptTimeout);
    if ($send !== '') {
        fwrite($socket, $send);
    }
    $data = (string)fread($socket, 256);
    fclose($socket);
    if ($data === '') {
        return "accepted the connection but sent no greeting within {$attemptTimeout} s";
    }
    $printable = preg_replace('/[^\x20-\x7e]+/', ' ', $data);

    return 'answered: ' . trim((string)$printable);
};

foreach ($hosts as $host) {
    if ($host !== 'localhost') {
        $say("probe {$host}:{$port}: " . $probe($host, $port, ''));
    }
}
$say('probe 127.0.0.1:6379 (redis): ' . $probe('127.0.0.1', 6379, "PING\r\n"));

$pdo = null;
$found = null;
$lastErrors = [];
while ($pdo === null && time() < $deadline) {
    foreach ($hosts as $host) {
        $started = microtime(true);
        try {
            $pdo = new PDO("mysql:host={$host};port={$port}", 'root', $rootPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => $attemptTimeout,
            ]);
            $found = $host;
            break;
        } catch (PDOException $e) {
            $lastErrors[$host] = $e->getMessage();
            $say(sprintf('%s: %s (%.1f s)', $host, $e->getMessage(), microtime(true) - $started));
            if (str_contains($e->getMessage(), '[1045]')) {
                $say("{$host} answers but refuses root with the configured password.");
                $say('Set SCRUTINIZER_DB_ROOT_PASSWORD to the service password.');
                exit(1);
            }
        }
    }
    if ($pdo === null && time() < $deadline) {
        sleep(min(3, $attemptTimeout));
    }
}

if ($pdo === null || $found === null) {
    $say('No database answered before the deadline. Last errors:');
    foreach ($lastErrors as $host => $message) {
        $say("  {$host}: {$message}");
    }
    exit(1);
}

$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$statements = [
    'CREATE DATABASE IF NOT EXISTS ansilume',
    'CREATE DATABASE IF NOT EXISTS ansilume_test',
    'CREATE USER IF NOT EXISTS `ansilume`@`%` IDENTIFIED BY ' . $pdo->quote('secret'),
    'GRANT ALL ON ansilume.* TO `ansilume`@`%`',
    'GRANT ALL ON ansilume_test.* TO `ansilume`@`%`',
    'FLUSH PRIVILEGES',
];
foreach ($statements as $sql) {
    $pdo->query($sql);
}

$env = is_file($envFile) ? (string)file_get_contents($envFile) : '';
$env = preg_match('/^DB_HOST=.*$/m', $env) === 1
    ? (string)preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=' . $found, $env)
    : $env . 'DB_HOST=' . $found . PHP_EOL;
file_put_contents($envFile, $env);

$say("Database {$version} at {$found}:{$port} is ready; databases and user created.");

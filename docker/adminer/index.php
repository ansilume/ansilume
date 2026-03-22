<?php

// Auto-login for dev — reads credentials from environment variables.
// Never use this in production.

function adminer_object()
{
    class AdminerAutoLogin extends Adminer
    {
        public function credentials(): array
        {
            return [
                $_ENV['DB_HOST']     ?? getenv('DB_HOST')     ?: 'db',
                $_ENV['DB_USER']     ?? getenv('DB_USER')     ?: '',
                $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
            ];
        }

        public function database(): string
        {
            return $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '';
        }

        public function login($login, $password): bool
        {
            return true;
        }
    }

    return new AdminerAutoLogin();
}

include './adminer.php';

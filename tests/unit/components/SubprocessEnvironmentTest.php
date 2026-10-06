<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SubprocessEnvironment;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Regression: ansible-lint (server) and ansible-playbook (runner) inherited the
 * parent's full environment via getenv(). Repository-controlled code running in
 * those subprocesses (an ansible.cfg vault password script, plugins, lint rule
 * directories) could read APP_SECRET_KEY, DB_PASSWORD, RUNNER_BOOTSTRAP_SECRET
 * and the other master secrets. Only an allowlist may reach such a subprocess.
 */
class SubprocessEnvironmentTest extends TestCase
{
    private const MASTER_SECRETS = [
        'APP_SECRET_KEY',
        'COOKIE_VALIDATION_KEY',
        'DB_PASSWORD',
        'DB_ROOT_PASSWORD',
        'REDIS_PASSWORD',
        'RUNNER_BOOTSTRAP_SECRET',
        'RUNNER_TOKEN',
        'SMTP_PASSWORD',
        'LDAP_BIND_PASSWORD',
    ];

    public function testForwardsAllowlistedOperationalVariables(): void
    {
        $env = SubprocessEnvironment::build([
            'PATH' => '/opt/bin:/usr/bin',
            'LANG' => 'de_DE.UTF-8',
            'TZ' => 'Europe/Berlin',
            'HTTPS_PROXY' => 'http://proxy:3128',
            'no_proxy' => 'localhost',
            'SSL_CERT_FILE' => '/etc/ssl/custom.pem',
            'HOSTNAME' => 'runner-host',
            'USER' => 'www-data',
            'LC_TIME' => 'de_DE.UTF-8',
        ]);

        $this->assertSame('runner-host', $env['HOSTNAME']);
        $this->assertSame('www-data', $env['USER']);
        $this->assertSame('de_DE.UTF-8', $env['LC_TIME']);
        $this->assertSame('/opt/bin:/usr/bin', $env['PATH']);
        $this->assertSame('de_DE.UTF-8', $env['LANG']);
        $this->assertSame('Europe/Berlin', $env['TZ']);
        $this->assertSame('http://proxy:3128', $env['HTTPS_PROXY']);
        $this->assertSame('localhost', $env['no_proxy']);
        $this->assertSame('/etc/ssl/custom.pem', $env['SSL_CERT_FILE']);
    }

    public function testForwardsOperatorAnsibleSettings(): void
    {
        $env = SubprocessEnvironment::build([
            'ANSIBLE_HOST_KEY_CHECKING' => 'False',
            'ANSIBLE_VAULT_PASSWORD_FILE' => '/etc/ansible/vault-pass',
        ]);

        $this->assertSame('False', $env['ANSIBLE_HOST_KEY_CHECKING']);
        $this->assertSame('/etc/ansible/vault-pass', $env['ANSIBLE_VAULT_PASSWORD_FILE']);
    }

    public function testNeverForwardsAnsilumeMasterSecrets(): void
    {
        $parent = ['PATH' => '/usr/bin'];
        foreach (self::MASTER_SECRETS as $name) {
            $parent[$name] = 'leak-canary-' . $name;
        }

        $env = SubprocessEnvironment::build($parent);

        foreach (self::MASTER_SECRETS as $name) {
            $this->assertArrayNotHasKey($name, $env, "{$name} must never reach a repository-controlled subprocess");
        }
        $this->assertStringNotContainsString('leak-canary', implode("\n", $env));
    }

    public function testDropsEverythingNotOnTheAllowlist(): void
    {
        $env = SubprocessEnvironment::build([
            'PATH' => '/usr/bin',
            'DB_HOST' => 'db',
            'API_URL' => 'http://nginx',
            'AWS_SECRET_ACCESS_KEY' => 'unlisted',
            'HOME' => '/var/www',
        ]);

        $this->assertSame(['PATH' => '/usr/bin'], $env);
    }

    /**
     * Regression (httpoxy): under php-fpm getenv() also returns the request's
     * FastCGI params, so a client "Proxy:" header shows up as HTTP_PROXY. The
     * allowlist must not hand that to web-triggered lint or inventory parsing.
     */
    public function testHttpProxyFromAFastCgiRequestIsNeverForwarded(): void
    {
        $env = SubprocessEnvironment::build([
            'PATH' => '/usr/bin',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REQUEST_METHOD' => 'GET',
            'HTTP_PROXY' => 'http://attacker.example:8080',
            'http_proxy' => 'http://proxy.internal:3128',
            'HTTPS_PROXY' => 'http://proxy.internal:3128',
        ]);

        $this->assertArrayNotHasKey('HTTP_PROXY', $env);
        $this->assertArrayNotHasKey('REQUEST_METHOD', $env);
        $this->assertSame('http://proxy.internal:3128', $env['http_proxy']);
        $this->assertSame('http://proxy.internal:3128', $env['HTTPS_PROXY']);
    }

    public function testHttpProxyIsForwardedOutsideARequestContext(): void
    {
        $env = SubprocessEnvironment::build(['PATH' => '/usr/bin', 'HTTP_PROXY' => 'http://proxy.internal:3128']);

        $this->assertSame('http://proxy.internal:3128', $env['HTTP_PROXY']);
    }

    public function testOverridesWinOverForwardedValues(): void
    {
        $env = SubprocessEnvironment::build(
            ['LANG' => 'POSIX', 'ANSIBLE_HOME' => '/root/.ansible', 'PATH' => '/usr/bin'],
            ['LANG' => 'C.UTF-8', 'ANSIBLE_HOME' => '/var/www/runtime/ansible-home', 'HOME' => '/tmp']
        );

        $this->assertSame('C.UTF-8', $env['LANG']);
        $this->assertSame('/var/www/runtime/ansible-home', $env['ANSIBLE_HOME']);
        $this->assertSame('/tmp', $env['HOME']);
    }

    public function testExtraNamesAreForwardedWhenExplicitlyAllowed(): void
    {
        $env = SubprocessEnvironment::build(
            ['PATH' => '/usr/bin', 'AWS_PROFILE' => 'prod', 'OTHER' => 'x'],
            [],
            ['AWS_PROFILE']
        );

        $this->assertSame('prod', $env['AWS_PROFILE']);
        $this->assertArrayNotHasKey('OTHER', $env);
    }

    public function testExtraNamesCannotForwardAnsilumeSecrets(): void
    {
        $env = SubprocessEnvironment::build(
            ['PATH' => '/usr/bin', 'RUNNER_BOOTSTRAP_SECRET' => 'leak-canary', 'APP_SECRET_KEY' => 'leak-canary'],
            [],
            ['RUNNER_BOOTSTRAP_SECRET', 'APP_SECRET_KEY']
        );

        $this->assertArrayNotHasKey('RUNNER_BOOTSTRAP_SECRET', $env);
        $this->assertArrayNotHasKey('APP_SECRET_KEY', $env);
    }

    public function testFallsBackToDefaultPathWhenParentHasNone(): void
    {
        $env = SubprocessEnvironment::build(['LANG' => 'C.UTF-8']);

        $this->assertSame(SubprocessEnvironment::DEFAULT_PATH, $env['PATH']);
    }

    public function testRealProcessEnvironmentDoesNotLeakPutenvSecrets(): void
    {
        $temp = new TemporaryEnvironment([
            'APP_SECRET_KEY' => 'leak-canary-app',
            'DB_PASSWORD' => 'leak-canary-db',
        ]);
        try {
            $env = SubprocessEnvironment::build(getenv() ?: []);
        } finally {
            $temp->restore();
        }

        $this->assertArrayNotHasKey('APP_SECRET_KEY', $env);
        $this->assertArrayNotHasKey('DB_PASSWORD', $env);
        $this->assertArrayHasKey('PATH', $env);
    }

    public function testParseNameListAcceptsCommasAndWhitespaceAndDropsInvalidNames(): void
    {
        $this->assertSame(
            ['AWS_PROFILE', 'OP_SERVICE_ACCOUNT_TOKEN', '_PRIVATE', 'X1'],
            SubprocessEnvironment::parseNameList(
                " AWS_PROFILE, OP_SERVICE_ACCOUNT_TOKEN\n_PRIVATE  1BAD,BAD-NAME, X1 ,,"
            )
        );
        $this->assertSame([], SubprocessEnvironment::parseNameList(''));
    }

    public function testIsForwardedReflectsAllowlistPrefixAndDenylist(): void
    {
        $this->assertTrue(SubprocessEnvironment::isForwarded('PATH'));
        $this->assertTrue(SubprocessEnvironment::isForwarded('ANSIBLE_STDOUT_CALLBACK'));
        $this->assertTrue(SubprocessEnvironment::isForwarded('LC_NUMERIC'));
        $this->assertTrue(SubprocessEnvironment::isForwarded('CUSTOM', ['CUSTOM']));
        $this->assertFalse(SubprocessEnvironment::isForwarded('CUSTOM'));
        $this->assertFalse(SubprocessEnvironment::isForwarded('DB_PASSWORD', ['DB_PASSWORD']));
    }
}

<?php

declare(strict_types=1);

namespace app\tests\unit\components;

/**
 * A loopback HTTPS server for the TLS tests of OutboundHttpClient.
 *
 * Each server makes a CA of its own and a certificate signed by it, for
 * 127.0.0.1 unless the test names another subject, so only a client that
 * trusts caFile() (OUTBOUND_CA_BUNDLE) verifies the server; no system CA
 * does. A child PHP process serves https://127.0.0.1:<port>/ and answers
 * every request with 200 and ANSWER.
 */
final class TlsLoopbackServer
{
    /** The body of every answer. */
    public const ANSWER = 'tls-ok';

    /**
     * The server, run by a child PHP process: the arguments are the
     * certificate, its key, the port and the file to create once it listens.
     * A client that refuses the certificate ends the handshake, and the
     * accept gives false with a warning, which the server skips.
     */
    private const SERVER = <<<'PHP'
        <?php
        [, $certificate, $key, $port, $ready] = $argv;
        $context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'local_pk' => $key]]);
        $server = stream_socket_server('tls://127.0.0.1:' . $port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            fwrite(STDERR, $error);
            exit(1);
        }
        touch($ready);
        set_error_handler(static fn (): bool => true);
        while (true) {
            $connection = stream_socket_accept($server, 3600);
            if ($connection === false) {
                continue;
            }
            $request = '';
            while (!str_contains($request, "\r\n\r\n")) {
                $chunk = fread($connection, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $request .= $chunk;
            }
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 6\r\nConnection: close\r\n\r\ntls-ok");
            fclose($connection);
        }
        PHP;

    /**
     * The OpenSSL configuration of the CA's and the server's certificate;
     * sprintf(): the server certificate's subjectAltName.
     */
    private const OPENSSL_CONFIG = <<<'CNF'
        [ req ]
        distinguished_name = req_distinguished_name
        [ req_distinguished_name ]
        [ v3_ca ]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash
        [ v3_server ]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature
        extendedKeyUsage = serverAuth
        subjectAltName = %s
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid
        CNF;

    /** @var resource|null */
    private $process;

    private string $directory = '';

    /**
     * Makes the certificates and starts the server.
     *
     * @param string $subjectAltName the names the server's certificate is for
     * @return string the server's base URL, https://127.0.0.1:<port>
     */
    public function start(string $subjectAltName = 'IP:127.0.0.1'): string
    {
        $this->directory = sys_get_temp_dir() . '/ansilume_tls_loopback_' . uniqid('', true);
        mkdir($this->directory, 0700, true);
        $this->makeCertificates($subjectAltName);
        $port = $this->freePort();
        file_put_contents($this->directory . '/server.php', self::SERVER);
        $ready = $this->directory . '/ready';
        $command = [PHP_BINARY, $this->directory . '/server.php', $this->directory . '/server.pem', $this->directory . '/server.key', (string)$port, $ready];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('TlsLoopbackServer: proc_open failed');
        }
        $this->process = $process;
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $deadline = microtime(true) + 5.0;
        while (!is_file($ready)) {
            if (microtime(true) > $deadline) {
                $this->stop();
                throw new \RuntimeException('TlsLoopbackServer: the server did not come up in time');
            }
            usleep(20000);
        }

        return 'https://127.0.0.1:' . $port;
    }

    /** The PEM file of the CA that signed the server's certificate. */
    public function caFile(): string
    {
        return $this->directory . '/ca.pem';
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
            $this->directory = '';
        }
    }

    private function makeCertificates(string $subjectAltName): void
    {
        $config = $this->directory . '/openssl.cnf';
        file_put_contents($config, sprintf(self::OPENSSL_CONFIG, $subjectAltName));
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $caKey = $this->key($options);
        $caCertificate = $this->sign(['commonName' => 'Ansilume test CA'], $caKey, null, $caKey, $options + ['x509_extensions' => 'v3_ca'], 1);
        $serverKey = $this->key($options);
        $serverCertificate = $this->sign(['commonName' => '127.0.0.1'], $serverKey, $caCertificate, $caKey, $options + ['x509_extensions' => 'v3_server'], 2);
        openssl_x509_export_to_file($caCertificate, $this->caFile());
        openssl_x509_export_to_file($serverCertificate, $this->directory . '/server.pem');
        openssl_pkey_export_to_file($serverKey, $this->directory . '/server.key', null, $options);
    }

    /**
     * @param array<string, string|int> $options
     */
    private function key(array $options): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('TlsLoopbackServer: no key: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * A certificate for $subject and $key, signed by $issuer (null: itself) with $issuerKey.
     *
     * @param array<string, string> $subject
     * @param array<string, string|int> $options
     */
    private function sign(array $subject, \OpenSSLAsymmetricKey $key, ?\OpenSSLCertificate $issuer, \OpenSSLAsymmetricKey $issuerKey, array $options, int $serial): \OpenSSLCertificate
    {
        $request = openssl_csr_new($subject, $key, $options);
        if (!$request instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('TlsLoopbackServer: no signing request: ' . openssl_error_string());
        }
        $certificate = openssl_csr_sign($request, $issuer, $issuerKey, 1, $options, $serial);
        if ($certificate === false) {
            throw new \RuntimeException('TlsLoopbackServer: no certificate: ' . openssl_error_string());
        }

        return $certificate;
    }

    private function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('TlsLoopbackServer: could not bind a probe socket');
        }
        $name = (string)stream_socket_get_name($probe, false);
        fclose($probe);

        return (int)substr($name, (int)strrpos($name, ':') + 1);
    }
}

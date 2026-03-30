<?php

declare(strict_types=1);

namespace app\services\audit;

/**
 * Writes JSON-encoded audit entries to syslog.
 *
 * Works with Promtail/Loki, rsyslog forwarding, Splunk syslog input,
 * and any other log aggregator that reads syslog.
 *
 * Configuration via environment:
 *   AUDIT_SYSLOG_IDENT    — syslog identifier (default: "ansilume")
 *   AUDIT_SYSLOG_FACILITY — syslog facility constant name (default: "LOG_LOCAL0")
 */
class SyslogAuditTarget implements AuditTargetInterface
{
    private string $ident;
    private int $facility;

    public function __construct(string $ident = 'ansilume', string $facility = 'LOG_LOCAL0')
    {
        $this->ident = $ident;
        $this->facility = defined($facility) ? (int)constant($facility) : LOG_LOCAL0;
    }

    public function send(array $entry): void
    {
        $payload = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{}';
        }

        openlog($this->ident, LOG_PID, $this->facility);
        syslog(LOG_INFO, $payload);
        closelog();
    }
}

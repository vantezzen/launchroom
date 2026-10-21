<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Models\AuditSink;
use App\Services\Audit\AuditBatch;
use App\Services\Audit\AuditDeliveryFailed;
use App\Services\Backups\SnapshotTags;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\UrlGuard;
use App\Support\AuditChain;
use Carbon\CarbonImmutable;

/**
 * Syslog over TLS (RFC 5425 octet counting, RFC 5424 messages; docs/09
 * R34): one message per record, facility 13 (log audit), severity 6, app
 * name `launchroom`, MSGID `audit`/`checkpoint`/`test`, the record JSON as
 * MSG. The host is vetted and resolved once by UrlGuard (R5), then the
 * connection goes to that IP. Syslog has no acknowledgements: a batch counts
 * as delivered when every byte was written.
 */
final readonly class SyslogTransport implements AuditSinkTransport
{
    private const int PRI = 13 * 8 + 6;

    public function __construct(
        private UrlGuard $guard,
        private SyslogConnector $connector,
    ) {}

    public function deliver(AuditSink $sink, AuditBatch $batch): void
    {
        $frames = '';

        foreach ($batch->records as $record) {
            $isEvent = ($record['type'] ?? null) === 'event';
            $fields = $record['fields'] ?? null;
            $at = $isEvent && is_array($fields) ? ($fields['created_at'] ?? null) : ($record['createdAt'] ?? null);
            $frames .= self::frame(is_string($at) ? $at : CarbonImmutable::now()->utc()->format(AuditChain::TIME_FORMAT), $isEvent ? 'audit' : 'checkpoint', AuditBatch::line($record));
        }

        $this->send($sink, $frames);
    }

    public function test(AuditSink $sink): string
    {
        $line = json_encode(['type' => 'test', 'instance' => SnapshotTags::instanceId(), 'sink' => $sink->public_id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->send($sink, self::frame(CarbonImmutable::now()->utc()->format(AuditChain::TIME_FORMAT), 'test', $line));

        return 'Connected and sent a test message. Check that your receiver shows it.';
    }

    /**
     * One octet-counted RFC 5424 message.
     */
    public static function frame(string $timestamp, string $msgId, string $message): string
    {
        $line = '<'.self::PRI.'>1 '.$timestamp.' '.self::hostname().' launchroom - '.$msgId.' - '.$message;

        return strlen($line).' '.$line;
    }

    private static function hostname(): string
    {
        $host = parse_url(config()->string('app.url'), PHP_URL_HOST);

        return is_string($host) && preg_match('/^[\x21-\x7e]{1,255}$/', $host) === 1 ? $host : 'launchroom';
    }

    private function send(AuditSink $sink, string $frames): void
    {
        $host = (string) $sink->configString('host');
        $port = $sink->configInt('port') ?? 6514;
        $guard = $sink->allow_private_network ? $this->guard->withPrivateNetwork() : $this->guard;

        try {
            // The guard only vets and resolves; the scheme just carries the port.
            $target = $guard->check('https://'.(str_contains($host, ':') ? "[{$host}]" : $host).':'.$port.'/');
        } catch (UnsafeUrl) {
            throw AuditDeliveryFailed::because($sink->allow_private_network
                ? 'This host is not allowed.'
                : 'This host is not allowed: it must be reachable on the public internet.');
        }

        $stream = $this->connector->connect($target, $sink->configString('caCertificate'), 10);

        try {
            $stream->write($frames);
        } finally {
            $stream->close();
        }
    }
}

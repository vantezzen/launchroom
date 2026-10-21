<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Services\Audit\AuditDeliveryFailed;
use App\Services\Http\ResolvedTarget;

/**
 * RFC 5425 transport: TCP to the vetted IP (never a second DNS lookup),
 * TLS with SNI and the peer verified against the system roots or the
 * sink's CA for the host name the owner entered. No client certificate.
 */
final class TlsSyslogConnector implements SyslogConnector
{
    public function connect(ResolvedTarget $target, ?string $caPem, int $timeoutSeconds): SyslogStream
    {
        $ssl = [
            'peer_name' => $target->host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        $caFile = null;

        if ($caPem !== null) {
            // OpenSSL streams only take a CA file path. A random 128-bit name
            // keeps the temp file unguessable (no predictable temp names).
            $caFile = sys_get_temp_dir().'/lr-syslog-ca-'.bin2hex(random_bytes(16)).'.pem';

            if (file_put_contents($caFile, $caPem) !== strlen($caPem)) {
                @unlink($caFile);

                throw AuditDeliveryFailed::because('Could not prepare the CA certificate.');
            }

            $ssl['cafile'] = $caFile;
        }

        $ip = str_contains($target->ip, ':') ? "[{$target->ip}]" : $target->ip;
        $context = stream_context_create(['ssl' => $ssl]);

        try {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client("tls://{$ip}:{$target->port}", $errno, $errstr, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        } finally {
            if ($caFile !== null) {
                @unlink($caFile);
            }
        }

        if ($socket === false) {
            throw AuditDeliveryFailed::because("Could not open a verified TLS connection to {$target->host}:{$target->port}.");
        }

        stream_set_timeout($socket, $timeoutSeconds);

        return new class($socket, $target) implements SyslogStream
        {
            /**
             * @param  resource  $socket
             */
            public function __construct(private $socket, private readonly ResolvedTarget $target) {}

            public function write(string $bytes): void
            {
                $offset = 0;
                $length = strlen($bytes);

                while ($offset < $length) {
                    $written = @fwrite($this->socket, substr($bytes, $offset));

                    if ($written === false || $written === 0) {
                        throw AuditDeliveryFailed::because("The connection to {$this->target->host} closed while sending.");
                    }

                    $offset += $written;
                }
            }

            public function close(): void
            {
                @fclose($this->socket);
            }
        };
    }
}

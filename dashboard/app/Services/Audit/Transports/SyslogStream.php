<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Services\Audit\AuditDeliveryFailed;

/**
 * An open TLS connection to a syslog receiver.
 */
interface SyslogStream
{
    /**
     * @throws AuditDeliveryFailed
     */
    public function write(string $bytes): void;

    public function close(): void;
}

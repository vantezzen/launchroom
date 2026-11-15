<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Services\Audit\AuditDeliveryFailed;

interface SyslogStream
{
    /**
     * @throws AuditDeliveryFailed
     */
    public function write(string $bytes): void;

    public function close(): void;
}

<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Services\Audit\AuditDeliveryFailed;
use App\Services\Http\ResolvedTarget;

/**
 * Opens a TLS connection to an already vetted address (the seam tests replace).
 */
interface SyslogConnector
{
    /**
     * @param  string|null  $caPem  trust only this CA instead of the system roots
     *
     * @throws AuditDeliveryFailed
     */
    public function connect(ResolvedTarget $target, ?string $caPem, int $timeoutSeconds): SyslogStream;
}

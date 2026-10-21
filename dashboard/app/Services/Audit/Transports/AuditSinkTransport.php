<?php

declare(strict_types=1);

namespace App\Services\Audit\Transports;

use App\Models\AuditSink;
use App\Services\Audit\AuditBatch;
use App\Services\Audit\AuditDeliveryFailed;

/**
 * Sends audit records to one kind of destination (docs/09 R34).
 */
interface AuditSinkTransport
{
    /**
     * @throws AuditDeliveryFailed
     */
    public function deliver(AuditSink $sink, AuditBatch $batch): void;

    /**
     * Sends a harmless test record; returns what the user should know.
     *
     * @throws AuditDeliveryFailed
     */
    public function test(AuditSink $sink): string;
}

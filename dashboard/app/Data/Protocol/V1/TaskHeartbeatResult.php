<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use App\Data\Protocol\ProtocolJson;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Signed response to a heartbeat (protocol/schema/v1/task-heartbeat-result.json).
 */
final class TaskHeartbeatResult extends Data implements ProtocolDocument
{
    public function __construct(
        public CarbonImmutable $leaseExpiresAt,
        public bool $cancel,
    ) {}

    public function toProtocol(): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'TaskHeartbeatResult',
            'leaseExpiresAt' => ProtocolJson::time($this->leaseExpiresAt),
            'cancel' => $this->cancel,
        ];
    }
}

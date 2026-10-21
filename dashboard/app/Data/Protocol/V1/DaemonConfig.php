<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use Spatie\LaravelData\Data;

/**
 * Signed response to GET /daemon/v1/config (protocol/schema/v1/daemon-config.json):
 * settings that may change after enrollment.
 */
final class DaemonConfig extends Data implements ProtocolDocument
{
    public function __construct(
        public ?NudgeConfig $nudge = null,
    ) {}

    public function toProtocol(): array
    {
        return array_filter([
            'apiVersion' => self::API_VERSION,
            'kind' => 'DaemonConfig',
            'nudge' => $this->nudge?->toProtocol(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}

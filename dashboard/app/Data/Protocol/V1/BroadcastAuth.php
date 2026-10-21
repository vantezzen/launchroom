<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use Spatie\LaravelData\Data;

/**
 * Signed Pusher channel authorization (protocol/schema/v1/broadcast-auth.json).
 */
final class BroadcastAuth extends Data implements ProtocolDocument
{
    public function __construct(
        public string $auth,
    ) {}

    public function toProtocol(): array
    {
        return ['apiVersion' => self::API_VERSION, 'kind' => 'BroadcastAuth', 'auth' => $this->auth];
    }
}

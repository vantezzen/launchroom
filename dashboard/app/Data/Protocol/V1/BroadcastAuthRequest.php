<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST /daemon/v1/broadcasting/auth (protocol/schema/v1/broadcast-auth-request.json).
 */
final class BroadcastAuthRequest extends Data
{
    public function __construct(
        #[In(['launchroom.vantezzen.io/v1'])]
        public string $apiVersion,
        #[In(['BroadcastAuthRequest'])]
        public string $kind,
        #[Regex('/^[0-9]{1,20}\.[0-9]{1,20}$/D')]
        public string $socketId,
        #[Regex('/^private-(?:daemon\.srv|session\.ses)_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public string $channel,
    ) {}
}

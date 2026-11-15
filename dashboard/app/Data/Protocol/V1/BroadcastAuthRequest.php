<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST /daemon/v1/broadcasting/auth (protocol/schema/v1/broadcast-auth-request.json).
 */
final class BroadcastAuthRequest extends Data
{
    public function __construct(
        #[In([ProtocolDocument::API_VERSION])]
        public string $apiVersion,
        #[In(['BroadcastAuthRequest'])]
        public string $kind,
        #[Regex(Grammar::SOCKET_ID)]
        public string $socketId,
        #[Regex('/^private-(?:daemon\.srv|session\.ses)_'.Grammar::ULID_BODY.'$/D')]
        public string $channel,
    ) {}
}

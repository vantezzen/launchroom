<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use App\Services\Sessions\SessionSecrets;
use App\Support\Crypto\OpaqueToken;
use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * The browser joining a session's channel with its single-use ticket.
 */
final class AuthorizeSessionData extends Data
{
    public function __construct(
        #[MapInputName('socket_id'), Regex(Grammar::SOCKET_ID)]
        public string $socketId,
        #[Regex('/^'.SessionSecrets::TICKET_PREFIX.OpaqueToken::BODY.'$/D')]
        public string $ticket,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * The browser joining a session's channel with its single-use ticket.
 */
final class AuthorizeSessionData extends Data
{
    public function __construct(
        #[MapInputName('socket_id'), Regex('/^[0-9]{1,20}\.[0-9]{1,20}$/')]
        public string $socketId,
        #[Regex('/^lr_st_[A-Za-z0-9_-]{43}$/')]
        public string $ticket,
    ) {}
}

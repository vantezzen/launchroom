<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use Spatie\LaravelData\Data;

/**
 * `reconnect`: the stream reached its maximum length; resume from `cursor`
 * (also the event id, so `Last-Event-ID` does it).
 */
final class StreamReconnectEvent extends Data
{
    public function __construct(
        public int $cursor,
    ) {}
}

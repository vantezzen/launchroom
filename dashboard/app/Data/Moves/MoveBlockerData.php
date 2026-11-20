<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * A service that still runs or keeps data on a server that is about to be
 * retired (docs/14 §13.2 retire, §13.4 hand-over and takeover): retiring
 * it is refused until it moved, was stopped or removed.
 */
final class MoveBlockerData extends Data
{
    public function __construct(
        public string $service,
        public string $project,
        public string $environment,
        public string $server,
        /** runs | data */
        public string $reason,
        public string $message,
        /** The service's page. */
        public ?string $href,
    ) {}
}

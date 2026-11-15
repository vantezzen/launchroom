<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * `server_moves.plan`: what the plan said when the move started.
 */
final class StartedMovePlanData extends Data
{
    /**
     * @param  list<string>  $warnings
     * @param  list<array{kind: string, host: string, addresses: list<string>, remove: list<string>}>  $dns
     */
    public function __construct(
        public ?string $platformFrom = null,
        public ?string $platformTo = null,
        public ?int $downtimeSeconds = null,
        public ?int $copyBytes = null,
        public array $warnings = [],
        public array $dns = [],
    ) {}
}

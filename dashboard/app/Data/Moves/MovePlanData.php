<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * What moving a server would do (docs/14 §13.2), shown before it starts and
 * re-checked when it does: the services and how each moves, the expected
 * downtime, DNS changes, and what stops it (blockers) or deserves a look
 * (warnings).
 */
final class MovePlanData extends Data
{
    /**
     * @param  list<MovePlanItemData>  $items
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  list<MoveDnsRecordData>  $dns
     */
    public function __construct(
        public MoveServerData $source,
        public ?MoveServerData $target,
        public array $items,
        public array $blockers,
        public array $warnings,
        public array $dns,
        /** The target's platform differs: built images are rebuilt for it. */
        public ?string $platformFrom,
        public ?string $platformTo,
        /** The target gets the edge role first (the source is the only edge). */
        public bool $addsEdge,
        /** Sum of the stateful services' downtime in seconds; null when a size is unknown. */
        public ?int $downtimeSeconds,
        public ?int $copyBytes,
        /** A backup destination exists, so "back up first" is offered. */
        public bool $canBackUpFirst,
    ) {}

    public function startable(): bool
    {
        return $this->target instanceof MoveServerData && $this->blockers === [];
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Enums\Moves\MoveItemKind;
use Spatie\LaravelData\Data;

/**
 * One service in a move plan (docs/14 §13.2): how it moves and what that
 * costs it.
 */
final class MovePlanItemData extends Data
{
    /**
     * @param  list<string>  $stops  services that stop while it is copied
     */
    public function __construct(
        public string $instanceId,
        public string $service,
        /** "<service> (<project> / <environment>)" */
        public string $name,
        public MoveItemKind $kind,
        /** Engine or "Files" label for stateful items. */
        public ?string $engine,
        /** Bytes to copy (the latest backup's size), null when unknown. */
        public ?int $expectedBytes,
        /** Expected downtime in seconds (0 for stateless), null when unknown. */
        public ?int $downtimeSeconds,
        /** Rebuilt for the target's platform before it moves. */
        public bool $rebuild,
        public array $stops,
        public ?string $note,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * `instance_settings.move_checklist`: the new dashboard's checklist after a
 * control-plane move (docs/14 §13.4), open until an instance owner finishes it.
 */
final class MoveChecklistStateData extends Data
{
    public function __construct(
        public string $startedAt,
        public ?string $moveId = null,
        public ?int $previousServerId = null,
        public ?string $previousUrl = null,
        public ?int $newServerId = null,
    ) {}
}

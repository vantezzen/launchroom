<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\CanvasNodeState;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A node's status line: state, label ("Building", "Active") and since when.
 */
final class NodeStatusData extends Data
{
    public function __construct(
        public CanvasNodeState $state,
        public string $label,
        public ?CarbonInterface $since = null,
        /** The latest deployment's public id, for links. */
        public ?string $deploymentId = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\NodeStatusReason;
use App\Enums\ServiceRemedy;
use App\Enums\StatusTone;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A service's status (D108, docs/08 §23): tone, why (`reason`, what code
 * compares), the words (`label`, "Building", "Live") and since when.
 * `cause` says in a few lowercase words what made it so ("exited", "ran out
 * of memory", "fra-1 isn't reporting"); `remedy` is the one-click fix.
 * `detail` carries the hold reason of a paused node.
 */
final class NodeStatusData extends Data
{
    public function __construct(
        public StatusTone $state,
        public NodeStatusReason $reason,
        public string $label,
        public ?CarbonInterface $since = null,
        /** The latest deployment's public id, for links. */
        public ?string $deploymentId = null,
        public ?string $detail = null,
        public ?string $cause = null,
        public ?ServiceRemedy $remedy = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\PlacementIssueKind;
use Spatie\LaravelData\Data;

/**
 * `service_instances.placement_issue`: why a service isn't fully placed
 * right now. Drives the degraded banner.
 */
final class PlacementIssueData extends Data
{
    public function __construct(
        public PlacementIssueKind $kind,
        public string $message,
        /** The server it concerns (public id), for the "restore elsewhere" link. */
        public ?string $serverId,
        public string $since,
    ) {}
}

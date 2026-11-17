<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Deployments\RuntimeFindingData;
use Spatie\LaravelData\Data;

/**
 * What the live version of a service went through while running (G7,
 * docs/08 §13): how often its containers restarted and its last exit with
 * the reason in words (App\Support\Deployments\ExitMeaning). Shown in the
 * service panel under the title, so a crash that healed itself is still
 * visible.
 */
final class ServiceRuntimeFactsData extends Data
{
    public function __construct(
        /** Restarts of the live version's replicas since it was deployed. */
        public int $restarts,
        /** "Restarted 3 times since this version went live (out of memory)", or null without restarts. */
        public ?string $restartText,
        /** "Exited with code 137: it ran out of memory.", or null when nothing exited with an error. */
        public ?string $lastExit,
        /** When it last exited (ISO 8601). */
        public ?string $lastExitAt,
        /** "Replica 2 on server-2", when the service runs more than one replica. */
        public ?string $lastExitWhere,
        /** What to do about it, when the exit says enough. */
        public ?string $advice,
        /** A known problem seen in the live version's logs (G12). */
        public ?RuntimeFindingData $finding = null,
        /** "Crashed 3 times in the last 24 hours (out of memory)" from `service_crashes`, or null without crashes. */
        public ?string $recentCrashes = null,
    ) {}
}

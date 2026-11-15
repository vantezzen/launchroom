<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Data\Protocol\V1\StateRejection;
use Spatie\LaravelData\Data;

/**
 * `servers.state_rejection`: launchroomd refused a NodeState and runs its
 * last good one (Report.stateRejected, docs/03 §5).
 */
final class StateRejectionData extends Data
{
    public function __construct(
        public int $version,
        public string $error,
        public string $rejectedAt,
    ) {}

    public static function fromProtocol(StateRejection $rejected): self
    {
        return new self($rejected->version, mb_substr($rejected->error, 0, 4096), $rejected->rejectedAt);
    }
}

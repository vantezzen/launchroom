<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Asks an edge to try obtaining certificates for these hosts again now
 * (protocol CertRetrySpec, "Retry now"). Every host must be routed on that
 * edge. Pinned: its creator checks the edge's `certificateRetry` feature.
 */
final class CertRetrySpec extends TaskSpec
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public array $hosts,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::CertRetry;
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Transfers\TransferVolumeData;
use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

/**
 * A service in one environment. `status`: `stopped` while it is stopped
 * (D68), else the latest deployment's status while it runs, else `live`
 * when a deployment serves, else the latest deployment's outcome, else
 * `not_deployed`.
 */
final class ServiceResource extends Data
{
    /**
     * @param  list<string>  $urls
     * @param  list<TransferVolumeData>  $volumes
     */
    public function __construct(
        public string $id,
        public string $environmentId,
        public string $name,
        public string $slug,
        public ServiceKind $kind,
        public string $status,
        public ?DeploymentResource $activeDeployment,
        public ?DeploymentResource $latestDeployment,
        public array $urls,
        public string $dashboardUrl,
        /** Volumes of this service (for `launchroom volume export`, docs/14 §12.7). */
        public array $volumes = [],
        /** When it was stopped (null: not stopped). */
        public ?string $stoppedAt = null,
    ) {}
}

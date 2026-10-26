<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Data\Servers\DeviceData;
use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

/**
 * A server that runs replicas of a deployment (docs/08 §12): the device
 * render with its status and the replicas standing on it.
 */
final class DeploymentServerData extends Data
{
    /**
     * @param  list<DeploymentReplicaData>  $replicas
     */
    public function __construct(
        public string $id,
        public string $name,
        public DeviceData $device,
        public ServerStatus $status,
        public array $replicas,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\Resources;
use App\Data\Protocol\V1\Sealed;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;

/**
 * A one-off container in an environment network (protocol RunOnceSpec,
 * docs/03 §7): release and seed commands, the sanitize command of a preview
 * clone, the start check of a generated Dockerfile. `env` is sealed at lease
 * time.
 */
final class RunOnceSpec extends TaskSpec
{
    /**
     * @param  list<string>|null  $command
     * @param  list<string>|null  $args
     * @param  list<string>|null  $maskEnv
     * @param  list<RunOnceMount>|null  $mounts  the service's volumes (release commands of a service with volumes; feature `releaseMounts`)
     */
    public function __construct(
        public string $service,
        public string $environment,
        public string $deployment,
        public string $image,
        public NetworkSpec $network,
        public string $timeout,
        public Resources $resources,
        public ?array $command = null,
        public ?array $args = null,
        public ?array $maskEnv = null,
        public ?RunOnceProbe $probe = null,
        public ?Sealed $env = null,
        public ?array $mounts = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::RunOnce;
    }

    public function requiredFeatures(): array
    {
        return array_values(array_filter([
            $this->probe instanceof RunOnceProbe ? DaemonFeature::GeneratedDockerfile : null,
            $this->mounts !== null ? DaemonFeature::ReleaseMounts : null,
        ]));
    }
}

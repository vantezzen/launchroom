<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolJson;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * NodeState `deleteVolumes[]`: an explicit request to delete a volume of a
 * deleted environment, project or preview (docs/05 §1.1). launchroomd deletes
 * it only if its labels match id, service and environment exactly and no
 * container uses it; the outcome comes back in Report.volumeDeletions.
 */
final class VolumeDeletion extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $service,
        public string $environment,
        public CarbonImmutable $requestedAt,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toProtocol(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'service' => $this->service,
            'environment' => $this->environment,
            'requestedAt' => ProtocolJson::time($this->requestedAt),
        ];
    }
}

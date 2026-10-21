<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

/**
 * `environment_config_files.managed`: per service public id, what the file
 * manages (docs/18 §8).
 */
final class ManagedConfigData extends Data
{
    /**
     * @param  array<string, ManagedServiceData>  $services
     */
    public function __construct(
        public array $services = [],
    ) {}

    public function service(string $serviceId): ?ManagedServiceData
    {
        return $this->services[$serviceId] ?? null;
    }
}

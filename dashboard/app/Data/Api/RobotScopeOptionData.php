<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * A project a robot token can be scoped to, with its services (docs/16 §1.4).
 */
final class RobotScopeOptionData extends Data
{
    /**
     * @param  list<RobotScopeServiceData>  $services
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $services,
    ) {}
}

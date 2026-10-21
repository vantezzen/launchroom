<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\DeploymentFailureClass;
use Spatie\LaravelData\Data;

final class ApiFailureData extends Data
{
    public function __construct(
        public ?DeploymentFailureClass $class,
        public ?string $message,
    ) {}
}

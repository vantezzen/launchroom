<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Enums\VariableExposure;
use App\Enums\VariableType;
use Spatie\LaravelData\Data;

/**
 * A project shared variable that applies to this environment, and whether
 * this service opted into it ("Use in: web, api", docs/06 §3.1).
 */
final class SharedVariableRowData extends Data
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $id,
        public string $key,
        public VariableType $type,
        public ?string $value,
        public ?string $preview,
        public array $errors,
        public bool $used,
        public VariableExposure $exposure = VariableExposure::Runtime,
    ) {}
}

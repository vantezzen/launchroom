<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Variables\VariableTargetData;
use App\Enums\VariableType;
use Spatie\LaravelData\Data;

/**
 * A variable row that applies to the service in this environment. Secret
 * values are never part of it (`value: null`; docs/16 §1.2).
 */
final class VariableResource extends Data
{
    /**
     * @param  list<VariableTargetData>  $targets
     */
    public function __construct(
        public string $id,
        public string $key,
        public VariableType $type,
        public ?string $value,
        public array $targets,
        /** Injected by a connection (read-only here). */
        public bool $connection,
        public bool $editable,
    ) {}
}

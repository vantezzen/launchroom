<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Enums\VariableExposure;
use App\Enums\VariableType;
use Spatie\LaravelData\Data;

/**
 * A variable row of the editor (docs/06 §3.4). `value` is only set for config
 * variables (readable by anyone who can see the service); secrets are
 * write-only and revealed through their own audited endpoint. `preview` is
 * the inline resolved value with every secret masked; `errors` lists broken
 * references (names only).
 */
final class VariableRowData extends Data
{
    /**
     * @param  list<string>  $references
     * @param  list<string>  $errors
     * @param  list<VariableTargetChipData>  $targets
     */
    public function __construct(
        public string $id,
        public string $key,
        public VariableType $type,
        public ?string $value,
        public VariableExposure $exposure,
        public bool $exposureOverridden,
        public bool $browserInlined,
        public bool $generated,
        public array $references,
        public ?string $preview,
        public array $errors,
        public ?string $connection,
        public bool $overridden,
        public array $targets,
        public bool $canEdit,
        public bool $canReveal,
        /** Set by launchroom.json (docs/18 §3.5): config values are edited in the file. */
        public bool $managedByFile = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Support\PublicId;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * One target of a variable (docs/06 §3.1): an environment (public id) or
 * "all previews", optionally only for branches matching a glob.
 */
final class VariableTargetData extends Data
{
    public function __construct(
        #[Regex('/^env_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $environmentId = null,
        public bool $allPreviews = false,
        #[Max(255), Regex('/^[A-Za-z0-9._\/*?\[\]-]+$/D')]
        public ?string $branchPattern = null,
    ) {}

    public static function environment(string $environmentPublicId): self
    {
        return new self(environmentId: $environmentPublicId);
    }

    public function isValidShape(): bool
    {
        return ($this->environmentId !== null && PublicId::isValid($this->environmentId, 'env')) !== $this->allPreviews
            && ($this->branchPattern === null || $this->allPreviews);
    }
}

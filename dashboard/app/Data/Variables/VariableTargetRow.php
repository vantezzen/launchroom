<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Models\VariableTarget;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * A resolved variable target (a `variable_targets` row): one environment by
 * internal id, or all previews, optionally only for branches matching a glob.
 */
final class VariableTargetRow extends Data
{
    public function __construct(
        public ?int $environmentId = null,
        public bool $allPreviews = false,
        public ?string $branchPattern = null,
    ) {
        if (($environmentId !== null) === $allPreviews) {
            throw new InvalidArgumentException('A target is either one environment or all previews.');
        }

        if (! $allPreviews) {
            $this->branchPattern = null;
        }
    }

    public static function environment(int $environmentId): self
    {
        return new self(environmentId: $environmentId);
    }

    public static function previews(?string $branchPattern = null): self
    {
        return new self(allPreviews: true, branchPattern: $branchPattern);
    }

    public static function of(VariableTarget $target): self
    {
        return new self($target->environment_id, $target->all_previews, $target->branch_pattern);
    }

    /**
     * Whether both select the same environments: two rows of one key with
     * the same target would make the value ambiguous.
     */
    public function sameAs(self $other): bool
    {
        return $this->environmentId !== null
            ? $this->environmentId === $other->environmentId
            : $other->allPreviews && $this->branchPattern === $other->branchPattern;
    }
}

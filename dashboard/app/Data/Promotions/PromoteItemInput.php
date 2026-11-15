<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Support\Variables\VariableKey;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * One selected item of Compare & promote. Secrets take the target's value in
 * `value`, or `copyValue` to copy the source's (blocked by the "production
 * secrets must differ" policy).
 */
final class PromoteItemInput extends Data
{
    public function __construct(
        #[Max(1024)]
        public string $path,
        #[Max(VariableKey::MAX_VALUE_BYTES)]
        public ?string $value = null,
        public bool $copyValue = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'value' => $this->value === null ? null : '[redacted]', 'copyValue' => $this->copyValue];
    }
}

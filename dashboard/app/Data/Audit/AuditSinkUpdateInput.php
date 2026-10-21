<?php

declare(strict_types=1);

namespace App\Data\Audit;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Change an audit log destination: rename, pause/resume, or a new webhook
 * signing secret (docs/09 R34). Destinations themselves are replaced by
 * removing and adding (the cursor belongs to one destination).
 */
final class AuditSinkUpdateInput extends Data
{
    public function __construct(
        #[Max(80)]
        public ?string $name = null,
        public ?bool $enabled = null,
        public bool $rotateSecret = false,
    ) {}
}

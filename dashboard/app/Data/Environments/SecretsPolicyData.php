<?php

declare(strict_types=1);

namespace App\Data\Environments;

use Spatie\LaravelData\Data;

/**
 * A project's secrets policy: production secrets must differ from preview
 * (docs/06 §3.1).
 */
final class SecretsPolicyData extends Data
{
    public function __construct(
        public bool $productionSecretsMustDiffer,
    ) {}
}

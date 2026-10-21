<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * A variable as the preview shows it: its key and what kind of value it
 * gets, never the value.
 */
final class BlueprintVariablePreviewData extends Data
{
    public function __construct(
        public string $key,
        public bool $secret,
        /** Generated per environment (`${{ secret(32) }}`). */
        public bool $generated,
        /** Points at another service (`${{db.DATABASE_URL}}`). */
        public bool $reference,
    ) {}
}

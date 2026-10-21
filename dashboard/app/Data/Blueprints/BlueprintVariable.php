<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * A variable to create (docs/17 §1), in launchroom's own syntax: literal
 * text, references (`${{db.DATABASE_URL}}`, `${{shared.KEY}}`) and generator
 * expressions (`${{ secret(32) }}`), which are evaluated once per environment
 * when the variable is created. The value can be a secret the user wrote
 * into their file, so it never leaves the server (previews list keys only).
 */
final class BlueprintVariable extends Data
{
    public function __construct(
        public string $key,
        public string $value,
        public bool $secret = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['key' => $this->key, 'value' => '[redacted]', 'secret' => $this->secret];
    }
}

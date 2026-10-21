<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * A name the Compose file interpolates (`${POSTGRES_PASSWORD}`): whether
 * the user's `.env` sets it, launchroom generates it (secret-looking names
 * nobody set) or it is still missing. Never carries a value.
 */
final class ComposeInputData extends Data
{
    public function __construct(
        public string $name,
        #[LiteralTypeScriptType("'provided' | 'generated' | 'missing'")]
        public string $status,
        public bool $required = false,
        public bool $secret = false,
        public ?string $message = null,
    ) {}
}

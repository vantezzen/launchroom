<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A runtime secret the app can't start without (protocol GeneratedSecret,
 * D86): Laravel's APP_KEY. GenerateDeclaredSecrets creates it once per
 * service and environment as `${{ secret(length[, encoding]) }}` when the
 * service has none.
 */
final class GeneratedSecret extends Data
{
    public const array ENCODINGS = ['alnum', 'base64', 'base64url', 'hex'];

    public function __construct(
        #[Regex(Grammar::ENV_NAME)]
        public string $name,
        /** Characters for alnum, random bytes for the other encodings. */
        #[Min(16), Max(128)]
        public int $length,
        #[In(self::ENCODINGS)]
        public string $encoding,
    ) {}

    /**
     * The value expression stored on the variable, so copies to other
     * environments regenerate it (GeneratedValues::sole()).
     */
    public function expression(): string
    {
        return $this->encoding === 'alnum'
            ? "\${{ secret({$this->length}) }}"
            : "\${{ secret({$this->length}, \"{$this->encoding}\") }}";
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Registry;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * POST /daemon/v1/registry-token (protocol/schema/v1/registry-token-request.json).
 */
final class RegistryTokenRequest extends Data
{
    /**
     * @param  list<RegistryScope>  $scopes
     */
    public function __construct(
        #[In(['launchroom.vantezzen.io/v1'])]
        public string $apiVersion,
        #[In(['RegistryTokenRequest'])]
        public string $kind,
        #[Min(1), Max(16)]
        public array $scopes,
        #[Regex('/^tsk_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public string|Optional $task = new Optional,
    ) {}
}

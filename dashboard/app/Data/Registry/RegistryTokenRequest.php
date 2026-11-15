<?php

declare(strict_types=1);

namespace App\Data\Registry;

use App\Data\Attributes\PublicIdOf;
use App\Data\Protocol\ProtocolDocument;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
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
        #[In([ProtocolDocument::API_VERSION])]
        public string $apiVersion,
        #[In(['RegistryTokenRequest'])]
        public string $kind,
        #[Min(1), Max(16)]
        public array $scopes,
        #[PublicIdOf('tsk')]
        public string|Optional $task = new Optional,
    ) {}
}

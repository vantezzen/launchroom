<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST /daemon/v1/enroll (protocol/schema/v1/enroll-request.json).
 */
final class EnrollRequest extends Data
{
    /**
     * @param  list<string>  $protocolVersions
     */
    public function __construct(
        #[In(['launchroom.vantezzen.io/v1'])]
        public string $apiVersion,
        #[In(['EnrollRequest'])]
        public string $kind,
        #[Regex('/^[A-Za-z0-9_.-]{16,256}$/D'), \SensitiveParameter]
        public string $token,
        #[Regex('/^ed25519:[A-Za-z0-9+\/]{43}=$/D')]
        public string $signingKey,
        #[Regex('/^x25519:[A-Za-z0-9+\/]{43}=$/D')]
        public string $sealingKey,
        #[Max(64), Regex('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D')]
        public string $daemonVersion,
        #[Min(1), Max(8)]
        public array $protocolVersions,
        public HostFacts $host,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['protocolVersions.*' => ['string', 'max:16']];
    }
}

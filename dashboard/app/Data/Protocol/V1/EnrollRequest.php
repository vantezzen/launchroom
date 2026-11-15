<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use App\Support\Protocol\Grammar;
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
        #[In([ProtocolDocument::API_VERSION])]
        public string $apiVersion,
        #[In(['EnrollRequest'])]
        public string $kind,
        #[Regex(Grammar::JOIN_TOKEN), \SensitiveParameter]
        public string $token,
        #[Regex(Grammar::ED25519_KEY)]
        public string $signingKey,
        #[Regex(Grammar::X25519_KEY)]
        public string $sealingKey,
        #[Max(64), Regex(Grammar::SEMVER)]
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

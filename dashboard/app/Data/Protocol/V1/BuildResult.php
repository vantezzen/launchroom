<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Outcome of a successful build task (protocol BuildResult): the pushed,
 * digest-pinned image plus what was detected, the build-variable hash and the
 * attestations attached to the image index.
 */
final class BuildResult extends Data
{
    /**
     * @param  list<string>|null  $attestations
     */
    public function __construct(
        #[Max(512), Regex(Grammar::IMAGE_REF)]
        public string $image,
        #[Min(0)]
        public int $durationMs,
        public ?BuildSummary $summary = null,
        #[Regex(Grammar::HEX64)]
        public ?string $secretsHash = null,
        public ?array $attestations = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['attestations' => ['nullable', 'array', 'max:2'], 'attestations.*' => ['string', 'in:sbom,provenance']];
    }
}

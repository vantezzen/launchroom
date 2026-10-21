<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

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
        #[Max(512), Regex('/^(?:localhost|[a-z0-9-]+(?:\.[a-z0-9-]+)+|[a-z0-9-]+(?:\.[a-z0-9-]+)*:[0-9]{1,5})(?:\/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)+@sha256:[a-f0-9]{64}$/D')]
        public string $image,
        #[Min(0)]
        public int $durationMs,
        public ?BuildSummary $summary = null,
        #[Regex('/^[a-f0-9]{64}$/D')]
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

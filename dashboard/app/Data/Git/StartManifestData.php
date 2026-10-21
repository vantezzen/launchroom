<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "Create GitHub App": optional organization and GitHub Enterprise URL.
 */
final class StartManifestData extends Data
{
    public function __construct(
        // GitHub login grammar: alphanumerics and single hyphens, ≤ 39 chars.
        #[Max(39), Regex('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/D')]
        public ?string $organization = null,
        // GitHub Enterprise Server base URL; checked by the URL guard too.
        #[Max(255), Regex('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$#D')]
        public ?string $githubUrl = null,
    ) {}
}

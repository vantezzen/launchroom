<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * BuildSummary `workspace` (protocol WorkspaceInfo, docs/04 §3): the
 * monorepo workspace an app belongs to. Paths are relative to the
 * repository root; a null root is the repository root.
 */
final class WorkspaceInfo extends Data
{
    /**
     * @param  list<string>|null  $dependsOn  workspace folders the app depends on (default watch paths)
     */
    public function __construct(
        #[Max(214)]
        public string $packageName,
        #[Max(64)]
        public string $manager,
        #[Max(1024), Regex(Grammar::REL_PATH)]
        public ?string $root = null,
        #[Max(64)]
        public ?string $tool = null,
        public ?array $dependsOn = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['dependsOn' => ['nullable', 'array', 'list', 'max:64'], 'dependsOn.*' => ['string', 'max:1024', 'regex:'.Grammar::REL_PATH]];
    }
}

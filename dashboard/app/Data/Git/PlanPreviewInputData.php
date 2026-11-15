<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Data\Attributes\PublicIdOf;
use App\Support\Git\GitRef;
use App\Support\Protocol\Grammar;
use Closure;
use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "We detected…" for a repository's branch and app directory (docs/04 §4);
 * `retry` asks again after a failed detection.
 */
#[MergeValidationRules]
final class PlanPreviewInputData extends Data
{
    public function __construct(
        #[PublicIdOf('ghr', 'gir')]
        public string $repository,
        #[Max(255)]
        public string $branch,
        #[Max(1024), Regex(Grammar::REL_PATH)]
        public ?string $rootDirectory = null,
        public bool $retry = false,
    ) {}

    /**
     * @return array<string, list<Closure>>
     */
    public static function rules(): array
    {
        return ['branch' => [GitRef::branchRule()]];
    }
}

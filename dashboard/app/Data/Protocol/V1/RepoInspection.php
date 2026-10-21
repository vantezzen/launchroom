<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * What launchroomd read of a repository for "Generate a Dockerfile"
 * (TaskCompletion.inspection of a plan with `inspect`, docs/20 §2, §6).
 * The dashboard filters and scrubs it again before anything is stored or
 * sent (App\Support\Dockerfiles\RepositoryContext).
 */
final class RepoInspection extends Data
{
    /**
     * @param  list<string>|null  $tree
     * @param  list<InspectedFile>|null  $files
     */
    public function __construct(
        #[Max(2000)]
        public ?array $tree,
        public bool $treeTruncated,
        #[Max(48)]
        public ?array $files,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['tree.*' => ['string', 'max:1024']];
    }
}

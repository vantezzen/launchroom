<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Data\Attributes\PublicIdOf;
use App\Support\Git\GitRef;
use Closure;
use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A picked repository (`ghr_…` GitHub, `gir_…` other providers) and branch,
 * before a project or service exists.
 */
#[MergeValidationRules]
final class RepositoryBranchData extends Data
{
    public function __construct(
        #[PublicIdOf('ghr', 'gir')]
        public string $repository,
        #[Max(255)]
        public string $branch,
    ) {}

    /**
     * @return array<string, list<Closure>>
     */
    public static function rules(): array
    {
        return ['branch' => [GitRef::branchRule()]];
    }
}

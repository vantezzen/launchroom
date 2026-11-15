<?php

declare(strict_types=1);

namespace App\Enums\Crypto;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Security\SecurityInvestigation;
use App\Models\Sessions\DatabaseQuery;
use App\Models\Variable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything stored under a project's data key (docs/09 R13), with its
 * associated data (a leading tag domain-separates the kinds) and the column
 * that holds it. Project key rotation re-encrypts and prune-checks every
 * case, so a new kind of ciphertext is a new case here. Environment
 * revisions embed `Variable` ciphertexts in their spec and are walked
 * separately.
 */
enum ProjectCiphertext: string
{
    /** `var|<team>|<project>|<variable public id>|<version>` */
    case Variable = 'var';

    /** A deployment's env snapshot: `env|<team>|<project>|<deployment public id>`. */
    case Snapshot = 'env';

    /** A SQL console statement (docs/07 `database_queries`): `query|<team>|<project>|<qry_ id>`. */
    case Query = 'query';

    /** An investigation's container inspections (docs/22 §8): `investigation|<team>|<project>|<ivg_ id>`. */
    case Inspections = 'investigation';

    public function associatedData(Project $project, string $rowPublicId, int $rowVersion = 0): string
    {
        $associatedData = "{$this->value}|{$project->team_id}|{$project->id}|{$rowPublicId}";

        return $this === self::Variable ? "{$associatedData}|{$rowVersion}" : $associatedData;
    }

    public function column(): string
    {
        return match ($this) {
            self::Variable => 'value',
            self::Snapshot => 'env_snapshot',
            self::Query => 'statement',
            self::Inspections => 'inspections',
        };
    }

    /**
     * @return Builder<covariant Model>
     */
    public function rows(Project $project): Builder
    {
        $inProject = static fn (QueryBuilder $query) => $query->where('project_id', $project->id);

        return match ($this) {
            self::Variable => Variable::query()->where('project_id', $project->id),
            self::Snapshot => Deployment::query()->whereNotNull('env_snapshot')->whereHas('environment', $inProject),
            self::Query => DatabaseQuery::query()->whereHas('serviceInstance.environment', $inProject),
            self::Inspections => SecurityInvestigation::query()->where('project_id', $project->id)->whereNotNull('inspections'),
        };
    }

    /**
     * The row version bound into the associated data (variables only).
     */
    public function rowVersion(Model $row): int
    {
        $version = $this === self::Variable ? $row->getAttribute('version') : 0;

        return is_int($version) ? $version : 0;
    }
}

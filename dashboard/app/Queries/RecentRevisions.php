<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\StagedChanges\RevisionData;
use App\Models\Environment;
use App\Models\EnvironmentRevision;

/**
 * The newest revisions of an environment, for "Revert to revision N"
 * (docs/06 §6.5).
 */
final class RecentRevisions
{
    private const int LIMIT = 10;

    /**
     * @return list<RevisionData>
     */
    public function for(Environment $environment): array
    {
        return array_values(EnvironmentRevision::query()
            ->where('environment_id', $environment->id)
            ->with('creator:id,name')
            ->latest('number')
            ->limit(self::LIMIT)
            ->get(['id', 'public_id', 'environment_id', 'number', 'message', 'created_by', 'created_at'])
            ->map(static fn (EnvironmentRevision $revision): RevisionData => new RevisionData(
                $revision->public_id,
                $revision->number,
                $revision->message,
                $revision->created_at->toIso8601String(),
                $revision->creator?->name,
                $revision->id === $environment->last_applied_revision_id,
            ))
            ->all());
    }
}

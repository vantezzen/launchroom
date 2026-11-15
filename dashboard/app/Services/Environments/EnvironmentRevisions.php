<?php

declare(strict_types=1);

namespace App\Services\Environments;

use App\Data\Specs\EnvironmentSpec;
use App\Models\Environment;
use App\Models\EnvironmentRevision;

/**
 * Immutable snapshots of an environment's intent (docs/06 §4), numbered per
 * environment. Callers hold the environment's row lock, which orders the
 * numbers.
 */
final class EnvironmentRevisions
{
    public const int MESSAGE_LIMIT = 500;

    public static function apply(Environment $environment, EnvironmentSpec $spec, ?string $message, ?int $createdBy): EnvironmentRevision
    {
        $revision = self::append($environment, $spec, $message, $createdBy);
        $environment->forceFill(['last_applied_revision_id' => $revision->id])->save();
        $environment->setRelation('lastAppliedRevision', $revision);

        return $revision;
    }

    /**
     * A new revision that is not applied (a deployment of one service only).
     */
    public static function append(Environment $environment, EnvironmentSpec $spec, ?string $message, ?int $createdBy): EnvironmentRevision
    {
        $max = EnvironmentRevision::query()->where('environment_id', $environment->id)->max('number');

        return EnvironmentRevision::query()->create([
            'team_id' => $environment->team_id,
            'environment_id' => $environment->id,
            'number' => (is_numeric($max) ? (int) $max : 0) + 1,
            'spec' => $spec,
            'message' => $message === null ? null : mb_substr($message, 0, self::MESSAGE_LIMIT),
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);
    }
}

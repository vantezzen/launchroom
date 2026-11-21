<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The narrowest token scope an API route serves (docs/16 §1.4,
 * App\Http\Api\TokenScopes). A token scoped to one project reaches
 * `project`, `service` and `own` routes; one scoped to a service reaches
 * `service` and `own` routes; unscoped tokens reach all of them.
 */
enum TokenScopeLevel: string
{
    /** Team-wide: servers, team analytics, security lists. */
    case Team = 'team';
    /** One project or environment as a whole. */
    case Project = 'project';
    /** One service: its deployments, logs, variables, domains, commands. */
    case Service = 'service';
    /** Only what the token itself holds (`GET /me`, its own downloads). */
    case Own = 'own';

    /** Whether a token scoped to $scope (project or service) may use a route of this level. */
    public function admits(self $scope): bool
    {
        return match ($this) {
            self::Team => false,
            self::Project => $scope === self::Project,
            self::Service, self::Own => true,
        };
    }
}

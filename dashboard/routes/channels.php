<?php

declare(strict_types=1);

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int|string $id): bool => (int) $user->id === (int) $id);

// Channel parameters named like route parameters resolve through the explicit
// route bindings (TeamScopedBindings: other teams' ids are not found), so in
// a real authorization request the callbacks receive models; a raw id (e.g.
// a direct call) is resolved the same team-scoped way. The policy decides
// (docs/09 R7).

// Deployment status and staged-change nudges of one environment.
Broadcast::channel('environments.{environment}', static function (User $user, Environment|string $environment): bool {
    if (is_string($environment)) {
        if (! PublicId::isValid($environment, Environment::publicIdPrefix())) {
            return false;
        }

        $environment = Environment::query()->where('public_id', $environment)->whereIn('team_id', $user->teamIdsQuery())->first();
    }

    return $environment instanceof Environment && Gate::forUser($user)->allows('view', $environment);
});

// Live status and log nudges of one deployment.
Broadcast::channel('deployments.{deployment}', static function (User $user, Deployment|string $deployment): bool {
    if (is_string($deployment)) {
        if (! PublicId::isValid($deployment, Deployment::publicIdPrefix())) {
            return false;
        }

        $deployment = Deployment::query()->where('public_id', $deployment)->whereIn('team_id', $user->teamIdsQuery())->first();
    }

    return $deployment instanceof Deployment && Gate::forUser($user)->allows('view', $deployment);
});

// Usage-metric nudges of a team's servers (server cards, server page).
Broadcast::channel('servers.{team}', static function (User $user, Team|string $team): bool {
    if (is_string($team)) {
        if (! PublicId::isValid($team, Team::publicIdPrefix())) {
            return false;
        }

        $team = Team::query()->where('public_id', $team)->whereIn('id', $user->teamIdsQuery())->first();
    }

    return $team instanceof Team && Gate::forUser($user)->allows('viewAny', [Server::class, $team]);
});

// Daemons' nudge channels (docs/03 §4) are never joinable by a browser
// session: daemons authorize through the signed POST /daemon/v1/broadcasting/auth.
Broadcast::channel('daemon.{id}', static fn (): bool => false);

// Interactive sessions (docs/03 §11.2): never through this endpoint. The
// browser joins only with its single-use ticket (POST /sessions/{ses}/authorize),
// launchroomd through its signed broadcasting auth.
Broadcast::channel('session.{id}', static fn (): bool => false);

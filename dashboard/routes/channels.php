<?php

declare(strict_types=1);

use App\Broadcasting\Channel;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Backups\BackupDestination;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Security\SecurityFinding;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int|string $id): bool => (int) $user->id === (int) $id);

// Channel parameters named like route parameters resolve through the same
// team-scoped bindings (other teams' ids are not found); a raw id (e.g. a
// direct call) is resolved the same way. The policy decides (docs/09 R7).

Broadcast::channel(Channel::Environment->pattern(), static function (User $user, Environment|string $environment): bool {
    $environment = is_string($environment) ? TeamScopedBindings::find('environment', $environment, $user) : $environment;

    return $environment instanceof Environment && Gate::forUser($user)->allows('view', $environment);
});

Broadcast::channel(Channel::Deployment->pattern(), static function (User $user, Deployment|string $deployment): bool {
    $deployment = is_string($deployment) ? TeamScopedBindings::find('deployment', $deployment, $user) : $deployment;

    return $deployment instanceof Deployment && Gate::forUser($user)->allows('view', $deployment);
});

Broadcast::channel(Channel::TeamServers->pattern(), static function (User $user, Team|string $team): bool {
    $team = is_string($team) ? TeamScopedBindings::find('team', $team, $user) : $team;

    return $team instanceof Team && Gate::forUser($user)->allows('viewAny', [Server::class, $team]);
});

Broadcast::channel(Channel::TeamSecurity->pattern(), static function (User $user, Team|string $team): bool {
    $team = is_string($team) ? TeamScopedBindings::find('team', $team, $user) : $team;

    return $team instanceof Team && Gate::forUser($user)->allows('viewAny', [SecurityFinding::class, $team]);
});

Broadcast::channel(Channel::TeamBackups->pattern(), static function (User $user, Team|string $team): bool {
    $team = is_string($team) ? TeamScopedBindings::find('team', $team, $user) : $team;

    return $team instanceof Team && Gate::forUser($user)->allows('viewAny', [BackupDestination::class, $team]);
});

// A member's own channel: only the user of that membership joins it.
Broadcast::channel(Channel::Member->pattern(), static fn (User $user, string $member): bool => PublicId::isValid($member, Membership::publicIdPrefix())
    && Membership::query()->where('public_id', $member)->where('user_id', $user->id)->exists());

// Daemons' nudge channels (docs/03 §4) are never joinable by a browser
// session: daemons authorize through the signed POST /daemon/v1/broadcasting/auth.
Broadcast::channel(Channel::Daemon->pattern(), static fn (): bool => false);

// Interactive sessions (docs/03 §11.2): never through this endpoint. The
// browser joins only with its single-use ticket (POST /sessions/{ses}/authorize),
// launchroomd through its signed broadcasting auth.
Broadcast::channel(Channel::Session->pattern(), static fn (): bool => false);

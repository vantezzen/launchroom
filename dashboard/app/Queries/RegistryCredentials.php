<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Registry\RegistryCredentialData;
use App\Data\Registry\RegistryCredentialsPageData;
use App\Models\Registry\RegistryCredential;
use App\Models\Team;
use App\Models\User;
use App\Support\Images\RegistryLayout;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Private registries (D91): the team's registries, without any
 * password or token.
 */
final readonly class RegistryCredentials
{
    public function forTeam(Team $team, User $user): RegistryCredentialsPageData
    {
        $credentials = RegistryCredential::query()
            ->where('team_id', $team->id)
            ->orderBy('host')
            ->get()
            ->map(RegistryCredentialData::fromCredential(...))
            ->all();

        return new RegistryCredentialsPageData(
            credentials: array_values($credentials),
            canManage: Gate::forUser($user)->allows('create', [RegistryCredential::class, $team]),
            mirrorPrefix: RegistryLayout::mirrorPrefix().'/'.strtolower($team->public_id),
        );
    }
}

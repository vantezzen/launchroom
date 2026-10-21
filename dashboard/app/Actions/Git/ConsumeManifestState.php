<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\ManifestState;
use App\Exceptions\DomainRuleViolation;
use App\Models\Team;
use App\Models\User;
use App\Support\Crypto\OpaqueToken;
use Illuminate\Support\Facades\Cache;

/**
 * Redeems a manifest state exactly once. The state is bound to the user who
 * started the flow and to their team; the caller still authorizes against
 * the returned team.
 */
final class ConsumeManifestState
{
    public function handle(User $user, string $state): ManifestState
    {
        if (preg_match('/^lr_ghm_[A-Za-z0-9_-]{43}$/D', $state) !== 1) {
            throw DomainRuleViolation::on('github', 'This GitHub App setup link is invalid. Start again from Settings → Git.');
        }

        // pull() = get + forget: a second use finds nothing.
        $payload = Cache::pull(StartGitHubAppManifest::STATE_CACHE_PREFIX.OpaqueToken::hash($state));

        if (! is_array($payload) || ($payload['user_id'] ?? null) !== $user->id) {
            throw DomainRuleViolation::on('github', 'This GitHub App setup link expired or was already used. Start again from Settings → Git.');
        }

        $team = Team::query()
            ->whereKey($payload['team_id'] ?? 0)
            ->whereIn('id', $user->teamIdsQuery())
            ->first();

        $webUrl = $payload['web_url'] ?? null;
        $apiUrl = $payload['api_url'] ?? null;

        if (! $team instanceof Team || ! is_string($webUrl) || ! is_string($apiUrl)) {
            throw DomainRuleViolation::forbidden('You are no longer a member of the team that started this setup.');
        }

        return new ManifestState($team, $webUrl, $apiUrl);
    }
}

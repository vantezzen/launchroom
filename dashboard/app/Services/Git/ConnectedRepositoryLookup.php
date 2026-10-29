<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Contracts\Git\SourceRepository;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;
use App\Models\Team;
use App\Services\Git\Providers\ApiGitProvider;
use App\Support\Git\PublicRepositoryUrl;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A pasted repository URL that one of the team's git connections already
 * reaches (docs/19 §4.3): a GitHub App installation's repository (cached
 * rows, no API call), a GitLab/Gitea/Forgejo connection on that host (its
 * cached rows, else one search through that connection), or a plain git
 * connection for the same URL. Always within $team; the first match wins.
 */
final readonly class ConnectedRepositoryLookup
{
    public function __construct(
        private GitProviders $providers,
    ) {}

    public function find(Team $team, PublicRepositoryUrl $url): ?SourceRepository
    {
        return $this->github($team, $url) ?? $this->apiConnection($team, $url) ?? $this->plain($team, $url);
    }

    private function github(Team $team, PublicRepositoryUrl $url): ?GitHubRepository
    {
        $hosts = GitHubApp::query()->where('team_id', $team->id)->pluck('web_url')
            ->map(static fn (mixed $web): string => self::host(is_string($web) ? $web : ''))
            ->all();

        if (! in_array($url->host, $hosts, true)) {
            return null;
        }

        return GitHubRepository::query()
            ->where('team_id', $team->id)
            ->whereRaw('lower(full_name) = ?', [strtolower($url->path)])
            ->whereRelation('installation', 'removed_at')
            ->with('installation.app')
            ->orderBy('id')
            ->first();
    }

    private function apiConnection(Team $team, PublicRepositoryUrl $url): ?GitRepository
    {
        $connections = GitConnection::query()
            ->where('team_id', $team->id)
            ->whereIn('provider', [GitProviderKind::GitLab->value, GitProviderKind::Gitea->value])
            ->where('status', GitConnectionStatus::Active->value)
            ->orderBy('id')
            ->get()
            ->filter(static fn (GitConnection $connection): bool => self::host($connection->base_url) === $url->host);

        if ($connections->isEmpty()) {
            return null;
        }

        $cached = GitRepository::query()
            ->where('team_id', $team->id)
            ->whereIn('git_connection_id', $connections->modelKeys())
            ->whereRaw('lower(full_name) = ?', [strtolower($url->path)])
            ->with('gitConnection')
            ->first();

        if ($cached instanceof GitRepository) {
            return $cached;
        }

        // Not seen yet: one search through the first connection on that host.
        $connection = $connections->first();
        $provider = $this->providers->for($connection->provider);

        if (! $provider instanceof ApiGitProvider) {
            return null;
        }

        try {
            $found = $provider->searchConnection($connection, $url->name());
        } catch (Throwable $e) {
            Log::warning('Looking up a pasted repository failed', ['connection' => $connection->public_id, 'error' => $e->getMessage()]);

            return null;
        }

        return array_find($found, static fn (GitRepository $repository): bool => strtolower($repository->full_name) === strtolower($url->path));
    }

    private function plain(Team $team, PublicRepositoryUrl $url): ?GitRepository
    {
        $connections = GitConnection::query()
            ->where('team_id', $team->id)
            ->where('provider', GitProviderKind::Git->value)
            ->where('status', GitConnectionStatus::Active->value)
            ->where('base_url', 'like', 'https://%')
            ->orderBy('id')
            ->get();

        $match = $connections->first(static fn (GitConnection $connection): bool => PublicRepositoryUrl::tryParse($connection->base_url)?->canonical() === $url->canonical());

        return $match instanceof GitConnection
            ? GitRepository::query()->where('git_connection_id', $match->id)->with('gitConnection')->first()
            : null;
    }

    private static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return is_int($port) ? "{$host}:{$port}" : $host;
    }
}

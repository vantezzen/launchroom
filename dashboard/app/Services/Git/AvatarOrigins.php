<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitConnection;
use App\Support\Git\GitHubAvatar;
use App\Support\Git\ProviderAvatar;
use Illuminate\Support\Facades\Cache;

/**
 * Hosts commit author avatars may come from, for the CSP's `img-src`
 * (docs/19 §7): GitHub's avatar host, Gravatar, and the https origins of
 * active GitLab/Gitea connections. Cached until a connection becomes active,
 * leaves active or is deleted (GitConnectionsChanged).
 */
final class AvatarOrigins
{
    private const string CACHE_KEY = 'launchroom:git:avatar-origins';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $origins = Cache::remember(self::CACHE_KEY, 300, static function (): array {
            $origins = [];

            $connections = GitConnection::query()
                ->whereIn('provider', GitProviderKind::apiConnections())
                ->where('status', GitConnectionStatus::Active->value)
                ->pluck('base_url');

            foreach ($connections as $baseUrl) {
                $origin = is_string($baseUrl) ? ProviderAvatar::origin($baseUrl) : null;

                if ($origin !== null) {
                    $origins[] = $origin;
                }
            }

            return array_values(array_unique($origins));
        });

        return ['https://'.GitHubAvatar::HOST, 'https://'.ProviderAvatar::GRAVATAR_HOST, ...$origins];
    }

    /**
     * $url when it is an https URL on one of {@see all()}, else null. Stored
     * avatars were checked by their provider when parsed; this keeps a row
     * written before a connection went away out of the page.
     */
    public static function allowed(?string $url): ?string
    {
        $parts = $url === null ? false : parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        $origin = 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

        return in_array($origin, self::all(), true) ? $url : null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

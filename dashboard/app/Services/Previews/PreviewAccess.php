<?php

declare(strict_types=1);

namespace App\Services\Previews;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Previews\PreviewShareLink;
use App\Models\Project;
use App\Models\User;
use App\Services\Crypto\ProjectKeyring;
use App\Support\Previews\PreviewToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Preview protection (docs/06 §5, docs/09 R20a). Previews live on the apps
 * domain, so the dashboard's session cookie never reaches them (and must
 * not: the apps domain runs user code). Access works through tokens:
 *
 * 1. Caddy forward-auths every request to `/auth/preview` with the preview
 *    host's own cookies. A valid host-only cookie (signed `cookie` token,
 *    bound to host + environment) or the CI bypass header → 200.
 * 2. Otherwise the endpoint redirects to `/auth/preview/start` on the
 *    dashboard origin, where the normal session applies (login if needed).
 *    A member of the preview's team gets a single-use `exchange` token
 *    (≤ 2 min, bound to the host) appended to the preview URL.
 * 3. Back on the preview host, `/auth/preview` swaps the exchange token (or
 *    a share link's `share` token) for the cookie and redirects to the same
 *    URL without the token.
 *
 * Cookies are re-checked against membership / share-link revocation (cached
 * briefly). The MAC key is derived from APP_KEY with a fixed context.
 */
final readonly class PreviewAccess
{
    public const string TOKEN_PARAMETER = '__lr_token';

    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    public function mintExchange(Environment $environment, string $host, User $user): string
    {
        $nonce = Str::random(32);

        return new PreviewToken(
            PreviewToken::KIND_EXCHANGE,
            $environment->public_id,
            strtolower($host),
            now()->addSeconds(config()->integer('launchroom.previews.exchange_ttl_seconds'))->getTimestamp(),
            userId: $user->id,
            nonce: $nonce,
        )->sign($this->key());
    }

    public function shareToken(PreviewShareLink $link, Environment $environment): string
    {
        return new PreviewToken(
            PreviewToken::KIND_SHARE,
            $environment->public_id,
            null,
            $link->expires_at->getTimestamp(),
            shareLink: $link->public_id,
        )->sign($this->key());
    }

    /**
     * Swaps an exchange or share token from the URL for a cookie value bound
     * to $host. Exchange tokens are single use. Null when the token is not
     * valid for this environment and host.
     *
     * @return array{value: string, expires: int}|null
     */
    public function exchange(#[SensitiveParameter] string $token, Environment $environment, string $host): ?array
    {
        $now = now()->getTimestamp();
        $host = strtolower($host);
        $exchange = PreviewToken::verify($token, PreviewToken::KIND_EXCHANGE, $this->key(), $now);

        if ($exchange instanceof PreviewToken) {
            if ($exchange->environment !== $environment->public_id || $exchange->host !== $host || $exchange->nonce === null || $exchange->userId === null) {
                return null;
            }

            // Single use: the first redemption wins.
            if (! Cache::add('launchroom:preview-exchange:'.hash('sha256', $exchange->nonce), true, config()->integer('launchroom.previews.exchange_ttl_seconds') + 60)) {
                return null;
            }

            if (! $this->userMayView($exchange->userId, $environment)) {
                return null;
            }

            $expires = now()->addHours(config()->integer('launchroom.previews.session_cookie_hours'))->getTimestamp();

            return ['value' => new PreviewToken(PreviewToken::KIND_COOKIE, $environment->public_id, $host, $expires, userId: $exchange->userId)->sign($this->key()), 'expires' => $expires];
        }

        $share = PreviewToken::verify($token, PreviewToken::KIND_SHARE, $this->key(), $now);

        if (! $share instanceof PreviewToken || $share->environment !== $environment->public_id || $share->shareLink === null) {
            return null;
        }

        $link = $this->usableLink($share->shareLink, $environment);

        if (! $link instanceof PreviewShareLink) {
            return null;
        }

        $link->forceFill(['last_used_at' => now()])->save();
        $expires = min($link->expires_at->getTimestamp(), now()->addHours(config()->integer('launchroom.previews.share_cookie_hours'))->getTimestamp());

        return ['value' => new PreviewToken(PreviewToken::KIND_COOKIE, $environment->public_id, $host, $expires, shareLink: $link->public_id)->sign($this->key()), 'expires' => $expires];
    }

    /**
     * Whether the preview host's cookie grants access right now.
     */
    public function cookieGrants(#[SensitiveParameter] string $cookie, Environment $environment, string $host): bool
    {
        $token = PreviewToken::verify($cookie, PreviewToken::KIND_COOKIE, $this->key(), now()->getTimestamp());

        if (! $token instanceof PreviewToken || $token->environment !== $environment->public_id || $token->host !== strtolower($host)) {
            return false;
        }

        $ttl = config()->integer('launchroom.previews.access_cache_seconds');

        if ($token->userId !== null) {
            $userId = $token->userId;

            return (bool) Cache::remember("launchroom:preview-access:user:{$userId}:{$environment->id}", $ttl, fn (): bool => $this->userMayView($userId, $environment));
        }

        if ($token->shareLink !== null) {
            $link = $token->shareLink;

            return (bool) Cache::remember("launchroom:preview-access:link:{$link}", $ttl, fn (): bool => $this->usableLink($link, $environment) instanceof PreviewShareLink);
        }

        return false;
    }

    /**
     * The CI bypass secret of a project (null until generated). It is a MAC
     * under the project DEK over the stored nonce, so only the nonce is stored.
     */
    public function bypassSecret(Project $project, PreviewSettings $settings): ?string
    {
        if ($settings->bypass_nonce === null) {
            return null;
        }

        return 'lrb_'.substr($this->keyring->mac($project, 'preview-bypass', $settings->bypass_nonce), 0, 48);
    }

    public function bypassGrants(#[SensitiveParameter] string $header, Project $project, PreviewSettings $settings): bool
    {
        $secret = $this->bypassSecret($project, $settings);

        return $secret !== null && $header !== '' && hash_equals($secret, $header);
    }

    public function userMayView(int $userId, Environment $environment): bool
    {
        $user = User::query()->whereKey($userId)->first();

        return $user instanceof User && $user->hasTeamPermission($environment->team_id, Permission::View);
    }

    private function usableLink(string $publicId, Environment $environment): ?PreviewShareLink
    {
        $link = PreviewShareLink::query()
            ->where('public_id', $publicId)
            ->where('team_id', $environment->team_id)
            ->whereIn('preview_id', Preview::query()->select('id')->where('environment_id', $environment->id))
            ->first();

        return $link instanceof PreviewShareLink && $link->isUsable() ? $link : null;
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'launchroom:preview-auth:v1', config()->string('app.key'), true);
    }
}

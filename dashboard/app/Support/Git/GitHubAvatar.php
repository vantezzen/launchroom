<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Commit author avatars (docs/08 §13, docs/09 R32): only ever URLs on
 * `https://avatars.githubusercontent.com`, rebuilt from parts we checked,
 * so a webhook payload can't point the dashboard's `<img>` (and the CSP's
 * img-src) anywhere else. Either the avatar URL GitHub sent (`/u/<id>`) or
 * one derived from a login.
 */
final class GitHubAvatar
{
    public const string HOST = 'avatars.githubusercontent.com';

    /** GitHub logins: alphanumerics and single hyphens, at most 39 characters. */
    private const string LOGIN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/D';

    /**
     * A checked avatar URL from GitHub's `avatar_url` (`/u/<id>` or
     * `/<login>`), else from the login.
     */
    public static function url(?string $avatarUrl, ?string $login = null): ?string
    {
        return self::fromAvatarUrl($avatarUrl) ?? self::fromLogin($login);
    }

    public static function fromAvatarUrl(?string $url): ?string
    {
        if ($url === null || strlen($url) > 255) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || strtolower($parts['host'] ?? '') !== self::HOST
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (preg_match('#^/u/([0-9]{1,12})$#D', $path, $id) === 1) {
            return 'https://'.self::HOST.'/u/'.$id[1].'?v=4';
        }

        return self::fromLogin(ltrim($path, '/'));
    }

    public static function fromLogin(?string $login): ?string
    {
        if ($login === null || preg_match(self::LOGIN, $login) !== 1 || str_ends_with($login, '[bot]')) {
            return null;
        }

        return 'https://'.self::HOST.'/'.$login.'?v=4';
    }
}

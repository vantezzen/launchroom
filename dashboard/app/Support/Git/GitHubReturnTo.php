<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Where the GitHub round trips (create the app, install it) come back to
 * (docs/design/flows.md §2): the place they started. The value travels as a
 * key, never as a URL, and only keys in this allow-list are honoured, so a
 * crafted `state` or form field can't redirect anywhere else.
 */
final class GitHubReturnTo
{
    public const string SESSION_KEY = 'launchroom.github_return_to';

    public const string DEFAULT = 'git';

    /** Key → route name. */
    public const array ROUTES = [
        'git' => 'git.edit',
        'onboarding' => 'onboarding.show',
    ];

    /** The allow-listed key, or null for anything else. */
    public static function key(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, self::ROUTES) ? $value : null;
    }

    /** Route name for a (possibly untrusted) key; unknown keys go to Settings → Git. */
    public static function route(mixed $value): string
    {
        return self::ROUTES[self::key($value) ?? self::DEFAULT];
    }
}

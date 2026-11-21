<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\CommitCheckState;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * One CI check on a commit (a GitHub check run or commit status, a GitLab
 * job or external status, a Gitea status; D111). `url` is the provider's
 * page for it, when it gave an http(s) one.
 */
final class CommitCheckData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
        public CommitCheckState $state,
        public ?string $url = null,
    ) {}

    /** The provider's link, only when it is an http(s) URL (never another scheme in an href). */
    public static function link(?string $url): ?string
    {
        return $url !== null && preg_match('#^https?://#i', $url) === 1 && mb_strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}

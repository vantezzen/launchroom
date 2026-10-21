<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Data;

/**
 * The head commit of a push.
 */
final class CommitData extends Data
{
    public function __construct(
        public string $sha,
        public string $message,
        public ?string $authorName,
        public ?string $authorEmail,
        public ?string $authorLogin,
        /** Checked avatar URL: avatars.githubusercontent.com (GitHubAvatar), or the connection’s origin / Gravatar (ProviderAvatar, docs/19 §7). */
        public ?string $authorAvatarUrl = null,
    ) {}
}

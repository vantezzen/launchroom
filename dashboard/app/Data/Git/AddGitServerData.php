<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * "Add a repository from any git server" (docs/19 §4.2): one URL
 * (`git@host:group/app.git`, `ssh://…`, `https://…`). The grammar is checked
 * by the action (SshUrl / https rules).
 */
final class AddGitServerData extends Data
{
    public function __construct(
        #[Max(1024)]
        public string $url,
        public bool $allowPrivateNetwork = false,
    ) {}
}

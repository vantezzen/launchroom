<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Data;

/**
 * What New project learned about a pasted public repository URL before
 * anything is stored (docs/19 §4.3): where it lives, its branches, and the
 * template whose upstream it is (D70); a repository the team's
 * connections reach comes with its picker row and push delivery. `problem` is set when the URL is well
 * formed but the repository can't be read (missing, private, rate limited).
 */
final class PublicRepositoryData extends Data
{
    /**
     * @param  list<string>  $branches  at most 100, the default first
     */
    public function __construct(
        /** The clone URL launchroom will use (`https://…`). */
        public string $url,
        /** `github.com/owner/app`. */
        public string $fullName,
        public string $name,
        public string $host,
        /** `github`, `gitlab`, `codeberg` or `git`: the mark shown. */
        public string $provider,
        public ?string $defaultBranch,
        public array $branches,
        /** A branch named in a pasted browser URL (`/tree/dev`). */
        public ?string $branch = null,
        /** Id of the template made for this repository, if any. */
        public ?string $template = null,
        public ?string $problem = null,
        /** The picker row when one of the team's connections reaches it: continue with it like Import. */
        public ?GitRepositoryData $repository = null,
        public bool $private = false,
        /** How pushes arrive: `webhook` (right away) or `polling` (every minute). */
        public string $pushes = 'polling',
        /** The connection it comes through ("your GitHub app", "gitlab.com (ada)"). */
        public ?string $via = null,
    ) {}
}

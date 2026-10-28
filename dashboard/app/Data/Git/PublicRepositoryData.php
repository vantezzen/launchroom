<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Data;

/**
 * What New project learned about a pasted public repository URL before
 * anything is stored (docs/19 §4.3): where it lives, its branches, and the
 * template whose upstream it is (D70). `problem` is set when the URL is well
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
    ) {}
}

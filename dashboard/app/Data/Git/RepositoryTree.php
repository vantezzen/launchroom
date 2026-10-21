<?php

declare(strict_types=1);

namespace App\Data\Git;

/**
 * The file paths of a repository at a ref (blobs only), for the import
 * inspector. `complete` is false when the provider truncated the listing.
 */
final readonly class RepositoryTree
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public array $paths,
        public bool $complete,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Data;

/**
 * `dockerfile_generations.context` (encrypted): the repository listing and
 * files the model sees, after the deny-list and scrubber
 * (App\Support\Dockerfiles\RepositoryContext::sanitize, docs/20 §3).
 */
final class RepositoryContextData extends Data
{
    /**
     * @param  list<string>  $tree
     * @param  list<array{path: string, content: string, truncated: bool}>  $files
     */
    public function __construct(
        public array $tree = [],
        public bool $treeTruncated = false,
        public array $files = [],
    ) {}
}

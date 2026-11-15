<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Data\Services\GitSource;
use Spatie\LaravelData\Data;

/**
 * The repository whose credential a task gets (`seal.cloneToken`, `seal.sourceCloneToken`).
 */
final class CloneSeal extends Data
{
    public function __construct(
        public GitSource $source,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * Which repositories a GitHub App installation can access.
 */
enum RepositorySelection: string
{
    case All = 'all';
    case Selected = 'selected';
}

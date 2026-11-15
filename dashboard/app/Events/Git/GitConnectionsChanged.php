<?php

declare(strict_types=1);

namespace App\Events\Git;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A git connection became active, left active or was deleted: the hosts
 * commit author avatars may come from changed (docs/19 §7).
 */
final class GitConnectionsChanged implements ShouldDispatchAfterCommit {}

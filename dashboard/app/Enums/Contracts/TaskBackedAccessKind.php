<?php

declare(strict_types=1);

namespace App\Enums\Contracts;

use App\Enums\AuditAction;
use App\Enums\DaemonFeature;
use App\Enums\Permission;

/**
 * A kind of access a user opens on a running service through a daemon task
 * (interactive sessions, docs/03 §11; remote access, docs/03 §12).
 */
interface TaskBackedAccessKind
{
    /** What opening and keeping it needs. */
    public function permission(): Permission;

    /** What the replica's launchroomd must announce. */
    public function feature(): DaemonFeature;

    public function startedAction(): AuditAction;

    public function endedAction(): AuditAction;
}

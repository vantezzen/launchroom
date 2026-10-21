<?php

declare(strict_types=1);

namespace App\Enums\Audit;

/**
 * What `launchroom:audit:verify` found (docs/09 R34).
 */
enum AuditChainProblem: string
{
    /** An entry's content no longer matches its hash. */
    case Edited = 'edited';
    /** An entry doesn't link to the one before it: something before it was deleted, inserted or reordered. */
    case BrokenLink = 'broken_link';
    /** The chain ends before a checkpoint's entry: entries were removed from the end. */
    case Truncated = 'truncated';
    /** A checkpoint's signature doesn't verify against the control plane's keys. */
    case ForgedCheckpoint = 'forged_checkpoint';
    /** A checkpoint names a hash or position the chain doesn't have. */
    case CheckpointMismatch = 'checkpoint_mismatch';
}

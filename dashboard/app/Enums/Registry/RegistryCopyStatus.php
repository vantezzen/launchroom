<?php

declare(strict_types=1);

namespace App\Enums\Registry;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a private image copy into launchroom's registry (D91):
 *
 *     pending → copying → succeeded → pending (a tag asked for again later)
 *                       ↘ failed → pending (asked for again by a later deploy)
 *
 * A copy by digest is never redone; a copy by tag is refreshed after a while
 * (App\Actions\Registry\MirrorPrivateImage), because the tag may move.
 * copying → pending recovers a copy whose worker was lost.
 */
enum RegistryCopyStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Copying = 'copying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Copying, self::Failed],
            self::Copying => [self::Succeeded, self::Failed, self::Pending],
            self::Succeeded => [self::Pending],
            self::Failed => [self::Pending],
        };
    }
}

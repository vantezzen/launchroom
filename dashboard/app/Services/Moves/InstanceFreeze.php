<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Models\InstanceSettings;
use Illuminate\Database\QueryException;

/**
 * The write freeze while the control plane is handed over to a new server
 * (docs/14 §13.4): from the moment the final dump is taken, this dashboard
 * refuses changes (web and API writes answer 503, git webhooks too, the
 * scheduler skips its ticks), so nothing is written that the new dashboard
 * wouldn't have. Pages stay readable. Once the new dashboard took over,
 * `moved_to` says where it is and every page points there.
 *
 * Apps keep running throughout: launchroomd keeps its last desired state.
 */
final class InstanceFreeze
{
    public static function active(): bool
    {
        return self::settings()?->frozen_at !== null;
    }

    public static function movedTo(): ?string
    {
        return self::settings()?->moved_to;
    }

    /**
     * Null only before the migrations created the table (a fresh install).
     * Any other database error propagates, so a write is never let through
     * on an unknown freeze state.
     */
    private static function settings(): ?InstanceSettings
    {
        try {
            return InstanceSettings::current();
        } catch (QueryException $e) {
            // PostgreSQL undefined_table.
            if ($e->getCode() === '42P01') {
                return null;
            }

            throw $e;
        }
    }

    public static function freeze(string $reason): void
    {
        InstanceSettings::lockedForUpdate()->forceFill(['frozen_at' => now(), 'frozen_reason' => mb_substr($reason, 0, 255)])->save();
    }

    public static function thaw(): void
    {
        InstanceSettings::lockedForUpdate()->forceFill(['frozen_at' => null, 'frozen_reason' => null, 'moved_to' => null])->save();
    }

    public static function moved(string $to): void
    {
        InstanceSettings::lockedForUpdate()->forceFill(['moved_to' => mb_substr($to, 0, 255)])->save();
    }
}

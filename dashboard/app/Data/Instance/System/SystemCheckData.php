<?php

declare(strict_types=1);

namespace App\Data\Instance\System;

use App\Enums\Instance\SystemCheckStatus;
use Spatie\LaravelData\Data;

/**
 * One control-plane check: an entry of `launchroom:doctor --json` (the
 * format `./launchroom doctor` reads: `id` matches `^[a-z0-9][a-z0-9.-]{0,63}$`,
 * `fix` is `clear-schedule-locks`, `restart-workers`, `cancel-update` or null) and a row of
 * Settings → System. Plain language, never a secret.
 */
final class SystemCheckData extends Data
{
    public const string FIX_CLEAR_SCHEDULE_LOCKS = 'clear-schedule-locks';

    public const string FIX_RESTART_WORKERS = 'restart-workers';

    public const string FIX_CANCEL_UPDATE = 'cancel-update';

    /**
     * @param  list<string>  $detail
     */
    public function __construct(
        public string $id,
        public string $title,
        public SystemCheckStatus $status,
        public string $summary,
        public array $detail = [],
        public ?string $fix = null,
    ) {}
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Production keeps runtime logs by default (D41 amendment, OD4): 1 day and
 * 256 MB, so the output of a container that crashed and was replaced is
 * still there. Applies to production environments that never had the
 * setting (null); new ones get it when they are created
 * (App\Models\Environment). Servers start shipping with their next desired
 * state.
 */
return new class extends Migration
{
    public const int DAYS = 1;

    public const int MAX_MB = 256;

    public function up(): void
    {
        DB::table('environments')
            ->where('kind', 'production')
            ->whereNull('runtime_log_retention_days')
            ->update(['runtime_log_retention_days' => self::DAYS, 'runtime_log_max_mb' => self::MAX_MB]);
    }

    public function down(): void
    {
        DB::table('environments')
            ->where('kind', 'production')
            ->where('runtime_log_retention_days', self::DAYS)
            ->where('runtime_log_max_mb', self::MAX_MB)
            ->update(['runtime_log_retention_days' => null, 'runtime_log_max_mb' => null]);
    }
};

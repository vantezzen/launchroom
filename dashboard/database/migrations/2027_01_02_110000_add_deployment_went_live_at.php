<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a deployment went live (reached `active`). `superseded` alone doesn't
 * say it: queued deployments collapsed by a newer one and finished prebuilds
 * end superseded too, and neither ever served. Rollback targets, the impact
 * window, the compare base and error-spike attribution read this instead
 * (docs/04 §6).
 *
 * Backfill: the route step succeeds right before `active`, so its end is
 * the moment; a live deployment without one uses its `finished_at` (set on
 * `active`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->timestamp('went_live_at')->nullable();
            $table->index(['service_instance_id', 'went_live_at']);
        });

        DB::statement(<<<'SQL'
            UPDATE deployments d
            SET went_live_at = COALESCE(s.finished_at, d.finished_at)
            FROM deployment_steps s
            WHERE s.deployment_id = d.id AND s.name = 'route' AND s.status = 'succeeded'
              AND d.status IN ('active', 'superseded') AND d.purpose = 'release'
            SQL);
        DB::statement("UPDATE deployments SET went_live_at = COALESCE(finished_at, updated_at) WHERE status = 'active' AND went_live_at IS NULL");
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex(['service_instance_id', 'went_live_at']);
            $table->dropColumn('went_live_at');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Updates that never get stuck (docs/11 §4.3):
 *
 * - `control_plane_updates` and `fleet_updates` can be `cancelled` (by the
 *   owner, before anything changed);
 * - `control_plane_updates.backup_attempts`: pre-upgrade backups started
 *   again after the worker running one stopped;
 * - `backup_runs.heartbeat_at` / `progress_bytes`: a control-plane backup
 *   (run inside the dashboard) reports that it is alive and how much it
 *   has stored, so one whose worker died is noticed and given up.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE control_plane_updates DROP CONSTRAINT control_plane_updates_status_check');
        DB::statement("ALTER TABLE control_plane_updates ADD CONSTRAINT control_plane_updates_status_check CHECK (status IN ('pending', 'backing_up', 'updating', 'succeeded', 'failed', 'rolled_back', 'cancelled'))");
        DB::statement('ALTER TABLE fleet_updates DROP CONSTRAINT fleet_updates_status_check');
        DB::statement("ALTER TABLE fleet_updates ADD CONSTRAINT fleet_updates_status_check CHECK (status IN ('running', 'succeeded', 'failed', 'cancelled'))");

        Schema::table('control_plane_updates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('backup_attempts')->default(0);
        });

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedBigInteger('progress_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropColumn(['heartbeat_at', 'progress_bytes']);
        });

        Schema::table('control_plane_updates', function (Blueprint $table): void {
            $table->dropColumn('backup_attempts');
        });

        DB::statement("UPDATE fleet_updates SET status = 'failed' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE fleet_updates DROP CONSTRAINT fleet_updates_status_check');
        DB::statement("ALTER TABLE fleet_updates ADD CONSTRAINT fleet_updates_status_check CHECK (status IN ('running', 'succeeded', 'failed'))");
        DB::statement("UPDATE control_plane_updates SET status = 'failed' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE control_plane_updates DROP CONSTRAINT control_plane_updates_status_check');
        DB::statement("ALTER TABLE control_plane_updates ADD CONSTRAINT control_plane_updates_status_check CHECK (status IN ('pending', 'backing_up', 'updating', 'succeeded', 'failed', 'rolled_back'))");
    }
};

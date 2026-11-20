<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves, round 2 (docs/14 §13.2, §13.4, D104):
 *
 * - server_move_items.status `stopped`: a failed service left stopped from
 *   the move page (or stopped elsewhere): it runs nowhere, so nothing moves.
 * - control_plane_moves.dns_switch: the DNS records to change after the
 *   hand-over (names, the new and the old server's addresses), taken when
 *   the hand-over starts, so the old dashboard can still show them once it
 *   handed over (its own copy of the new server goes quiet).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE server_move_items DROP CONSTRAINT server_move_items_status_check');
        DB::statement("ALTER TABLE server_move_items ADD CONSTRAINT server_move_items_status_check CHECK (status IN ('pending', 'building', 'backing_up', 'stopping', 'exporting', 'preparing', 'importing', 'starting', 'done', 'failed', 'rolling_back', 'rolled_back', 'cancelled', 'skipped', 'stopped'))");

        Schema::table('control_plane_moves', function (Blueprint $table): void {
            $table->jsonb('dns_switch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('control_plane_moves', function (Blueprint $table): void {
            $table->dropColumn('dns_switch');
        });

        DB::statement("UPDATE server_move_items SET status = 'skipped' WHERE status = 'stopped'");
        DB::statement('ALTER TABLE server_move_items DROP CONSTRAINT server_move_items_status_check');
        DB::statement("ALTER TABLE server_move_items ADD CONSTRAINT server_move_items_status_check CHECK (status IN ('pending', 'building', 'backing_up', 'stopping', 'exporting', 'preparing', 'importing', 'starting', 'done', 'failed', 'rolling_back', 'rolled_back', 'cancelled', 'skipped'))");
    }
};

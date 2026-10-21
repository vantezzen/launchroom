<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Updates as one guided flow (docs/11 §4, docs/08 §11):
 *
 * - instance_settings: the update channel chosen in the UI (null = the
 *   configured default, LAUNCHROOM_UPDATES_CHANNEL) and opt-in automatic
 *   updates (off by default), remembered with the owner who turned them on
 *   (the actor of automatic updates).
 * - control_plane_updates: `update_servers` (then roll every server to the
 *   new version, started by the new dashboard once it is up; the rollout it
 *   started is `fleet_update_id`) and `automatic` (started by the nightly
 *   check, not a person).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('update_channel', 8)->nullable();
            $table->boolean('auto_update')->default(false);
            $table->foreignId('auto_update_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('auto_update_changed_at')->nullable();
        });

        DB::statement("ALTER TABLE instance_settings ADD CONSTRAINT instance_settings_update_channel_check CHECK (update_channel IS NULL OR update_channel IN ('stable', 'beta'))");

        Schema::table('control_plane_updates', function (Blueprint $table): void {
            $table->boolean('update_servers')->default(false);
            $table->boolean('automatic')->default(false);
            $table->foreignId('fleet_update_id')->nullable()->constrained('fleet_updates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('control_plane_updates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fleet_update_id');
            $table->dropColumn(['update_servers', 'automatic']);
        });

        DB::statement('ALTER TABLE instance_settings DROP CONSTRAINT IF EXISTS instance_settings_update_channel_check');

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('auto_update_by');
            $table->dropColumn(['update_channel', 'auto_update', 'auto_update_changed_at']);
        });
    }
};

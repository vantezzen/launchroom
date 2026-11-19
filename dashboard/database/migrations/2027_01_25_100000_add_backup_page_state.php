<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Backups page (docs/14 §3, §9.2):
 *
 * - volumes can be left out of backups ("Back up this volume"), with who
 *   turned that off and when;
 * - removing a destination keeps it as a row (`removed_at`): runs,
 *   restores, point-in-time archives and verifications keep pointing at it
 *   as read-only history ("storage removed"). Their foreign keys no longer
 *   cascade, so a hard delete can't take history with it;
 * - launchroom's own destination can be a copy of a team destination
 *   (`copied_from_id`), and a failing destination says why (`error_kind`);
 * - "Back up everything now" groups its runs (`backup_runs.batch_id`).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array HISTORY = ['backup_runs', 'restore_runs', 'wal_archives', 'backup_verifications', 'backup_schedules'];

    public function up(): void
    {
        Schema::table('volumes', function (Blueprint $table): void {
            $table->boolean('backup_enabled')->default(true);
            $table->foreignId('backup_disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('backup_disabled_at')->nullable();
        });

        Schema::table('backup_destinations', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('copied_from_id')->nullable()->constrained('backup_destinations')->nullOnDelete();
            $table->string('error_kind', 32)->nullable();
        });

        DB::statement('DROP INDEX IF EXISTS backup_destinations_one_default_per_team');
        DB::statement('DROP INDEX IF EXISTS backup_destinations_one_instance');
        DB::statement('CREATE UNIQUE INDEX backup_destinations_one_default_per_team ON backup_destinations (team_id) WHERE is_default AND team_id IS NOT NULL AND removed_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX backup_destinations_one_instance ON backup_destinations ((team_id IS NULL)) WHERE team_id IS NULL AND removed_at IS NULL');

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->string('batch_id', 40)->nullable();
            $table->index(['team_id', 'batch_id']);
        });

        foreach (self::HISTORY as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['destination_id']);
                $table->foreign('destination_id')->references('id')->on('backup_destinations')->noActionOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::HISTORY as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['destination_id']);
                $table->foreign('destination_id')->references('id')->on('backup_destinations')->cascadeOnDelete();
            });
        }

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'batch_id']);
            $table->dropColumn('batch_id');
        });

        DB::statement('DROP INDEX IF EXISTS backup_destinations_one_default_per_team');
        DB::statement('DROP INDEX IF EXISTS backup_destinations_one_instance');
        DB::table('backup_destinations')->whereNotNull('removed_at')->delete();
        DB::statement('CREATE UNIQUE INDEX backup_destinations_one_default_per_team ON backup_destinations (team_id) WHERE is_default AND team_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX backup_destinations_one_instance ON backup_destinations ((team_id IS NULL)) WHERE team_id IS NULL');

        Schema::table('backup_destinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('copied_from_id');
            $table->dropColumn(['removed_at', 'error_kind']);
        });

        Schema::table('volumes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('backup_disabled_by');
            $table->dropColumn(['backup_enabled', 'backup_disabled_at']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Volume backups and final backups (docs/14 §2, §9.12, §9.13):
 *
 * - volume backups: `volumes.backup_consistency` (live | pause), schedules
 *   and runs with subject `volume` (a run covers one volume), restores of
 *   volume snapshots (`restore_runs.subject`, target volume);
 * - final backups before deletion: `deletion_requests` (an environment,
 *   project or staged service removal waits for its final backups, or a
 *   recorded override) and `volume_retirements.final_backup_snapshot`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volumes', function (Blueprint $table): void {
            $table->string('backup_consistency', 8)->default('live');
        });
        DB::statement("ALTER TABLE volumes ADD CONSTRAINT volumes_backup_consistency_check CHECK (backup_consistency IN ('live', 'pause'))");

        DB::statement('ALTER TABLE backup_schedules DROP CONSTRAINT backup_schedules_subject_check');
        DB::statement("ALTER TABLE backup_schedules ADD CONSTRAINT backup_schedules_subject_check CHECK ((subject IN ('database', 'volume') AND service_instance_id IS NOT NULL AND team_id IS NOT NULL) OR (subject = 'control_plane' AND service_instance_id IS NULL AND team_id IS NULL))");

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->foreignId('volume_id')->nullable()->constrained()->nullOnDelete();
        });
        // One backup in flight per database, and per volume.
        DB::statement('DROP INDEX backup_runs_one_in_flight');
        DB::statement("CREATE UNIQUE INDEX backup_runs_one_in_flight ON backup_runs (service_instance_id, (COALESCE(volume_id, 0))) WHERE status IN ('pending', 'running') AND service_instance_id IS NOT NULL");

        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->string('subject', 16)->default('database');
            // Volume restores: the volume the snapshot is restored into.
            $table->foreignId('volume_id')->nullable()->constrained()->nullOnDelete();
        });
        DB::statement("ALTER TABLE restore_runs ADD CONSTRAINT restore_runs_subject_check CHECK (subject IN ('database', 'volume'))");

        Schema::create('deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // project | environment | service_instance; the subject row goes
            // with the deletion, so this is not a foreign key.
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_public_id', 40);
            $table->string('subject_name', 255);
            $table->string('status', 16);
            // The final backup runs (one per database / volume).
            $table->jsonb('backup_run_ids')->default('[]');
            $table->string('error', 2048)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
        DB::statement("ALTER TABLE deletion_requests ADD CONSTRAINT deletion_requests_subject_check CHECK (subject_type IN ('project', 'environment', 'service_instance'))");
        DB::statement("ALTER TABLE deletion_requests ADD CONSTRAINT deletion_requests_status_check CHECK (status IN ('backing_up', 'blocked', 'ready', 'overridden', 'completed', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX deletion_requests_one_open ON deletion_requests (subject_type, subject_id) WHERE status NOT IN ('completed', 'cancelled')");

        Schema::table('volume_retirements', function (Blueprint $table): void {
            // The restic snapshot of the final backup (the run row goes with its service).
            $table->string('final_backup_snapshot', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('volume_retirements', function (Blueprint $table): void {
            $table->dropColumn('final_backup_snapshot');
        });
        Schema::dropIfExists('deletion_requests');
        DB::statement('ALTER TABLE restore_runs DROP CONSTRAINT IF EXISTS restore_runs_subject_check');
        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('volume_id');
            $table->dropColumn('subject');
        });
        DB::statement('DROP INDEX backup_runs_one_in_flight');
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('volume_id');
        });
        DB::statement("CREATE UNIQUE INDEX backup_runs_one_in_flight ON backup_runs (service_instance_id) WHERE status IN ('pending', 'running') AND service_instance_id IS NOT NULL");
        DB::statement('ALTER TABLE backup_schedules DROP CONSTRAINT backup_schedules_subject_check');
        DB::statement("ALTER TABLE backup_schedules ADD CONSTRAINT backup_schedules_subject_check CHECK ((subject = 'database' AND service_instance_id IS NOT NULL AND team_id IS NOT NULL) OR (subject = 'control_plane' AND service_instance_id IS NULL AND team_id IS NULL))");
        DB::statement('ALTER TABLE volumes DROP CONSTRAINT IF EXISTS volumes_backup_consistency_check');
        Schema::table('volumes', function (Blueprint $table): void {
            $table->dropColumn('backup_consistency');
        });
    }
};

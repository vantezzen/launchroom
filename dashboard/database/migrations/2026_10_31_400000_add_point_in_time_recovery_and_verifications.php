<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time recovery and restore verification (docs/14 §10):
 *
 * - point-in-time recovery for Postgres: `wal_archives` (one per database
 *   instance: on/off, retention, base backup schedule and the WAL shipping
 *   state launchroomd reports), base backup runs (`backup_runs.subject =
 *   base_backup`) and point-in-time restores (`restore_runs.subject =
 *   base_backup`, `point_in_time`, `restored_to`);
 * - automatic restore verification: `backup_verifications`;
 * - metrics: `backup_runs.duration_ms` (from the task result).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wal_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('backup_destinations')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamp('enabled_at')->nullable();
            // How far back a point in time can be chosen (base backups and WAL are kept this long).
            $table->unsignedSmallInteger('keep_days')->default(7);
            // Base backups: standard 5-field cron in UTC (daily, staggered).
            $table->string('base_cron', 64);
            $table->timestamp('last_base_run_at')->nullable();
            $table->timestamp('last_maintenance_at')->nullable();
            // The WAL shipping state launchroomd reports (Report.walArchives).
            $table->string('state', 16)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('last_archived_at')->nullable();
            $table->string('last_segment', 24)->nullable();
            $table->unsignedInteger('pending_segments')->default(0);
            // Secret-free, short.
            $table->string('message', 1024)->nullable();
            $table->foreignId('reported_by_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->timestamp('failing_since')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE wal_archives ADD CONSTRAINT wal_archives_state_check CHECK (state IS NULL OR state IN ('active', 'waiting', 'failing'))");
        DB::statement('ALTER TABLE wal_archives ADD CONSTRAINT wal_archives_keep_days_check CHECK (keep_days BETWEEN 1 AND 35)');

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('duration_ms')->nullable();
        });
        // One backup in flight per database and kind (dump or base backup), and per volume.
        DB::statement('DROP INDEX backup_runs_one_in_flight');
        DB::statement("CREATE UNIQUE INDEX backup_runs_one_in_flight ON backup_runs (service_instance_id, subject, (COALESCE(volume_id, 0))) WHERE status IN ('pending', 'running') AND service_instance_id IS NOT NULL");

        Schema::table('restore_runs', function (Blueprint $table): void {
            // Point-in-time restores: where replay stopped (null = as far as the archive goes) and where it got to.
            $table->timestamp('point_in_time', 6)->nullable();
            $table->timestamp('restored_to', 6)->nullable();
        });
        DB::statement('ALTER TABLE restore_runs DROP CONSTRAINT restore_runs_subject_check');
        DB::statement("ALTER TABLE restore_runs ADD CONSTRAINT restore_runs_subject_check CHECK (subject IN ('database', 'volume', 'base_backup'))");

        Schema::create('backup_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->foreignId('destination_id')->constrained('backup_destinations')->cascadeOnDelete();
            $table->string('subject', 16);
            $table->foreignId('volume_id')->nullable()->constrained()->nullOnDelete();
            $table->string('snapshot_id', 64);
            $table->timestamp('snapshot_taken_at')->nullable();
            $table->string('reason', 16);
            $table->string('status', 16)->default('pending');
            // [{name, passed, detail}] from the daemon (secret-free).
            $table->jsonb('checks')->default('[]');
            $table->unsignedBigInteger('tables')->nullable();
            $table->unsignedBigInteger('rows')->nullable();
            $table->unsignedBigInteger('files')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('restored_to', 6)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('error', 2048)->nullable();
            $table->foreignId('daemon_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['service_instance_id', 'id']);
        });
        DB::statement("ALTER TABLE backup_verifications ADD CONSTRAINT backup_verifications_status_check CHECK (status IN ('pending', 'running', 'passed', 'failed'))");
        DB::statement("ALTER TABLE backup_verifications ADD CONSTRAINT backup_verifications_subject_check CHECK (subject IN ('database', 'volume', 'base_backup'))");
        DB::statement("ALTER TABLE backup_verifications ADD CONSTRAINT backup_verifications_reason_check CHECK (reason IN ('scheduled', 'manual'))");
        DB::statement("CREATE UNIQUE INDEX backup_verifications_one_in_flight ON backup_verifications (service_instance_id) WHERE status IN ('pending', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_verifications');
        DB::statement('ALTER TABLE restore_runs DROP CONSTRAINT IF EXISTS restore_runs_subject_check');
        DB::statement("DELETE FROM restore_runs WHERE subject = 'base_backup'");
        DB::statement("ALTER TABLE restore_runs ADD CONSTRAINT restore_runs_subject_check CHECK (subject IN ('database', 'volume'))");
        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->dropColumn(['point_in_time', 'restored_to']);
        });
        DB::statement('DROP INDEX backup_runs_one_in_flight');
        DB::statement("DELETE FROM backup_runs WHERE subject = 'base_backup'");
        DB::statement("CREATE UNIQUE INDEX backup_runs_one_in_flight ON backup_runs (service_instance_id, (COALESCE(volume_id, 0))) WHERE status IN ('pending', 'running') AND service_instance_id IS NOT NULL");
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
        Schema::dropIfExists('wal_archives');
    }
};

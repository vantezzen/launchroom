<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backups (docs/14, docs/07 "Backups").
 */
return new class extends Migration
{
    public function up(): void
    {
        // One restic repository per destination. team_id null = the instance
        // (control-plane) destination, of which there is at most one.
        Schema::create('backup_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 16);
            $table->string('endpoint', 512);
            $table->string('bucket', 63);
            $table->string('region', 32)->nullable();
            $table->string('prefix', 256)->default('');
            // Admin-only, audited override for LAN/mesh endpoints and plain
            // http (docs/09 R5). Off: https only, public addresses only.
            $table->boolean('allow_private_network')->default(false);
            $table->boolean('is_default')->default(false);
            // Envelope-encrypted (docs/09 R13): a per-destination data key
            // wrapped by the KEK; values bound to backup_destination|team|id|field.
            $table->text('wrapped_dek');
            $table->string('kek_id', 32);
            $table->text('credentials');
            $table->text('repository_password');
            $table->string('status', 16)->default('unverified');
            $table->timestamp('last_checked_at')->nullable();
            // Secret-free, short.
            $table->string('last_error', 1024)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE backup_destinations ADD CONSTRAINT backup_destinations_kind_check CHECK (kind IN ('s3'))");
        DB::statement("ALTER TABLE backup_destinations ADD CONSTRAINT backup_destinations_status_check CHECK (status IN ('unverified', 'checking', 'ok', 'failing'))");
        DB::statement('CREATE UNIQUE INDEX backup_destinations_one_default_per_team ON backup_destinations (team_id) WHERE is_default AND team_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX backup_destinations_one_instance ON backup_destinations ((team_id IS NULL)) WHERE team_id IS NULL');

        Schema::create('backup_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('subject', 16);
            $table->foreignId('service_instance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('backup_destinations')->cascadeOnDelete();
            // Standard 5-field cron, evaluated in UTC every minute.
            $table->string('cron', 64);
            $table->jsonb('retention');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_maintenance_at')->nullable();
            $table->timestamps();

            $table->unique('service_instance_id');
        });

        DB::statement("ALTER TABLE backup_schedules ADD CONSTRAINT backup_schedules_subject_check CHECK ((subject = 'database' AND service_instance_id IS NOT NULL AND team_id IS NOT NULL) OR (subject = 'control_plane' AND service_instance_id IS NULL AND team_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX backup_schedules_one_control_plane ON backup_schedules (subject) WHERE subject = 'control_plane'");

        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('backup_schedules')->nullOnDelete();
            $table->foreignId('destination_id')->constrained('backup_destinations')->cascadeOnDelete();
            $table->string('subject', 16);
            $table->foreignId('service_instance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason', 16);
            $table->string('status', 16)->default('pending');
            // Full restic snapshot id (hex).
            $table->string('snapshot_id', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('added_bytes')->nullable();
            $table->foreignId('agent_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 2048)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['service_instance_id', 'status']);
            $table->index(['subject', 'status']);
        });

        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_reason_check CHECK (reason IN ('scheduled', 'manual', 'pre_upgrade', 'pre_restore', 'pre_delete', 'pre_deploy'))");
        // At most one backup in flight per database (docs/14 §7: one heavy backup at a time).
        DB::statement("CREATE UNIQUE INDEX backup_runs_one_in_flight ON backup_runs (service_instance_id) WHERE status IN ('pending', 'running') AND service_instance_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX backup_runs_one_control_plane_in_flight ON backup_runs (subject) WHERE status IN ('pending', 'running') AND subject = 'control_plane'");

        Schema::create('restore_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('backup_destinations')->cascadeOnDelete();
            $table->string('snapshot_id', 64);
            // The database the snapshot was taken from, and where it is restored to.
            $table->foreignId('source_service_instance_id')->constrained('service_instances')->cascadeOnDelete();
            $table->foreignId('target_service_instance_id')->nullable()->constrained('service_instances')->nullOnDelete();
            $table->string('mode', 16);
            $table->string('status', 32);
            $table->foreignId('safety_backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->foreignId('agent_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error', 2048)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_service_instance_id', 'status']);
        });

        DB::statement("ALTER TABLE restore_runs ADD CONSTRAINT restore_runs_mode_check CHECK (mode IN ('new_service', 'in_place'))");
        DB::statement("ALTER TABLE restore_runs ADD CONSTRAINT restore_runs_status_check CHECK (status IN ('waiting_for_safety_backup', 'waiting_for_target', 'restoring', 'succeeded', 'failed'))");

        // Recovery kit (docs/14 §4). The passphrase is never stored: only the
        // Argon2id salt/limits and the X25519 public key derived from it. The
        // control-plane key bundle is sealed to that key; deriving it again
        // from the passphrase is the only way to open it (and verifies it).
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('instance_id', 40)->nullable();
            $table->string('recovery_public_key', 64)->nullable();
            $table->jsonb('recovery_kdf')->nullable();
            $table->timestamp('recovery_passphrase_set_at')->nullable();
            $table->timestamp('recovery_kit_downloaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn(['instance_id', 'recovery_public_key', 'recovery_kdf', 'recovery_passphrase_set_at', 'recovery_kit_downloaded_at']);
        });
        Schema::dropIfExists('restore_runs');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_schedules');
        Schema::dropIfExists('backup_destinations');
    }
};

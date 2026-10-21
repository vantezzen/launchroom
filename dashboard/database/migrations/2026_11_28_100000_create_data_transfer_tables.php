<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data import and export (D55, docs/14 §12): chunked transfers through the
 * dashboard (encrypted at rest), import runs and export runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained('service_instances')->cascadeOnDelete();
            $table->foreignId('volume_id')->nullable()->constrained('volumes')->nullOnDelete();
            $table->string('direction', 16);
            $table->string('purpose', 32);
            $table->string('status', 16);
            // Display only (sanitised); never a host path.
            $table->string('file_name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('chunk_bytes');
            $table->unsignedInteger('chunk_count');
            // Index => lowercase hex SHA-256 of each received chunk.
            $table->jsonb('chunk_digests')->nullable();
            // SHA-256 over the chunks' raw digests in order (docs/14 §12.1).
            $table->string('digest', 64)->nullable();
            $table->string('format', 32)->nullable();
            $table->boolean('compressed')->default(false);
            $table->string('source_version', 64)->nullable();
            $table->string('detail', 255)->nullable();
            // What the sniffer found (family, versions), for the import checks.
            $table->jsonb('sniff')->nullable();
            // Per-transfer data key, wrapped by the KEK (context transfer:<public id>).
            $table->text('wrapped_dek');
            $table->string('kek_id', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 2048)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            // One-use download link (only its SHA-256), bound to a user.
            $table->string('link_token_hash', 64)->nullable()->unique();
            $table->foreignId('link_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('link_expires_at')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->index(['service_instance_id', 'status']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE data_transfers ADD CONSTRAINT data_transfers_direction_check CHECK (direction IN ('upload', 'download'))");
        DB::statement("ALTER TABLE data_transfers ADD CONSTRAINT data_transfers_purpose_check CHECK (purpose IN ('database_import', 'volume_import', 'database_export', 'volume_export'))");
        DB::statement("ALTER TABLE data_transfers ADD CONSTRAINT data_transfers_status_check CHECK (status IN ('receiving', 'ready', 'failed', 'expired'))");

        Schema::create('data_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained('service_instances')->cascadeOnDelete();
            $table->string('subject', 16);
            $table->foreignId('volume_id')->nullable()->constrained('volumes')->nullOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('data_transfers')->nullOnDelete();
            $table->string('source_kind', 8);
            // File name, or the URL without user and password.
            $table->string('source_label', 255);
            // A connection URL sealed to the target server for reserved_task_id
            // (crypto_box_seal; the dashboard can't open it). Wiped once the
            // task exists.
            $table->text('sealed_source')->nullable();
            $table->string('reserved_task_id', 40)->nullable();
            // The server the import runs on (the sealed URL is bound to it).
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('replace')->default(false);
            $table->boolean('stop_dependents')->default(true);
            $table->unsignedSmallInteger('strip_components')->default(0);
            $table->unsignedInteger('owner_uid')->nullable();
            $table->unsignedInteger('owner_gid')->nullable();
            $table->string('status', 32);
            $table->foreignId('safety_backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->foreignId('daemon_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('result')->nullable();
            $table->string('error', 2048)->nullable();
            $table->timestamp('holding_since')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['service_instance_id', 'status']);
        });

        DB::statement("ALTER TABLE data_import_runs ADD CONSTRAINT data_import_runs_status_check CHECK (status IN ('waiting_for_safety_backup', 'holding', 'importing', 'succeeded', 'failed', 'cancelled'))");
        DB::statement("ALTER TABLE data_import_runs ADD CONSTRAINT data_import_runs_subject_check CHECK (subject IN ('database', 'volume'))");
        DB::statement("ALTER TABLE data_import_runs ADD CONSTRAINT data_import_runs_source_check CHECK (source_kind IN ('file', 'url'))");

        Schema::create('data_export_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained('service_instances')->cascadeOnDelete();
            $table->string('subject', 16);
            $table->foreignId('volume_id')->nullable()->constrained('volumes')->nullOnDelete();
            $table->string('source', 16);
            $table->string('snapshot_id', 64)->nullable();
            $table->foreignId('transfer_id')->nullable()->constrained('data_transfers')->nullOnDelete();
            $table->foreignId('daemon_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->string('file_name', 255);
            $table->string('error', 2048)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['service_instance_id', 'status']);
        });

        DB::statement("ALTER TABLE data_export_runs ADD CONSTRAINT data_export_runs_status_check CHECK (status IN ('preparing', 'ready', 'failed', 'expired'))");
        DB::statement("ALTER TABLE data_export_runs ADD CONSTRAINT data_export_runs_source_check CHECK (source IN ('live', 'snapshot'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('data_export_runs');
        Schema::dropIfExists('data_import_runs');
        Schema::dropIfExists('data_transfers');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies of a database or volume from one environment into another of the
 * same project (D110, docs/14 §14): an export of the source chained into an
 * import of the target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_copy_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_service_instance_id')->constrained('service_instances')->cascadeOnDelete();
            $table->foreignId('target_service_instance_id')->constrained('service_instances')->cascadeOnDelete();
            $table->string('subject', 16);
            $table->foreignId('source_volume_id')->nullable()->constrained('volumes')->nullOnDelete();
            $table->foreignId('target_volume_id')->nullable()->constrained('volumes')->nullOnDelete();
            $table->string('source_point', 16);
            $table->string('snapshot_id', 64)->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->foreignId('export_run_id')->nullable()->constrained('data_export_runs')->nullOnDelete();
            $table->foreignId('import_run_id')->nullable()->constrained('data_import_runs')->nullOnDelete();
            $table->string('status', 16);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 2048)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['target_service_instance_id', 'status']);
            $table->index(['source_service_instance_id', 'id']);
        });

        DB::statement("ALTER TABLE data_copy_runs ADD CONSTRAINT data_copy_runs_status_check CHECK (status IN ('exporting', 'importing', 'succeeded', 'failed', 'cancelled'))");
        DB::statement("ALTER TABLE data_copy_runs ADD CONSTRAINT data_copy_runs_subject_check CHECK (subject IN ('database', 'volume'))");
        DB::statement("ALTER TABLE data_copy_runs ADD CONSTRAINT data_copy_runs_point_check CHECK (source_point IN ('latest_backup', 'backup', 'live'))");
        DB::statement('ALTER TABLE data_copy_runs ADD CONSTRAINT data_copy_runs_distinct_check CHECK (source_service_instance_id <> target_service_instance_id)');
        // One copy into a target at a time.
        DB::statement("CREATE UNIQUE INDEX data_copy_runs_one_open ON data_copy_runs (target_service_instance_id) WHERE status IN ('exporting', 'importing')");
    }

    public function down(): void
    {
        Schema::dropIfExists('data_copy_runs');
    }
};

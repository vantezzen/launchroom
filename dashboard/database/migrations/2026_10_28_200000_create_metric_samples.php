<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage metrics (docs/design/direction.md §8.2, docs/07): per-minute host and
 * workload samples from Report.metrics, rolled up to 10-minute and daily rows
 * (`resolution` in minutes: 1 kept 48 h, 10 kept 30 days, 1440 kept a year;
 * App\Actions\Metrics\RollupMetrics). Primary keys make every write an
 * idempotent upsert, so a minute a server sends twice is harmless. Workload
 * rows carry their service instance and project (attributed at write time:
 * placements of finished workloads are deleted later).
 *
 * Also the server appearance overrides: the device the user picked
 * ("Looks like") and the confirmed location (label + coordinates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metric_samples', function (Blueprint $table): void {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('resolution');
            $table->timestampTz('at', 0);
            $table->float('cpu_pct', 24)->nullable();
            $table->integer('mem_used_mb')->nullable();
            $table->integer('mem_total_mb')->nullable();
            $table->integer('disk_used_mb')->nullable();
            $table->integer('disk_total_mb')->nullable();
            $table->bigInteger('net_rx_bps')->nullable();
            $table->bigInteger('net_tx_bps')->nullable();
            $table->float('load1', 24)->nullable();
            $table->primary(['server_id', 'resolution', 'at']);
        });

        Schema::create('workload_metric_samples', function (Blueprint $table): void {
            $table->string('workload_id', 29);
            $table->smallInteger('resolution');
            $table->timestampTz('at', 0);
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Replica slot (placements.slot): series per replica survive redeploys (new workload ids).
            $table->smallInteger('replica')->default(0);
            $table->float('cpu_cores', 24)->nullable();
            $table->integer('mem_mb')->nullable();
            $table->primary(['workload_id', 'resolution', 'at']);
            $table->index(['server_id', 'resolution', 'at']);
            $table->index(['service_instance_id', 'resolution', 'at']);
        });

        DB::statement('ALTER TABLE server_metric_samples ADD CONSTRAINT server_metric_samples_resolution_check CHECK (resolution IN (1, 10, 1440))');
        DB::statement('ALTER TABLE workload_metric_samples ADD CONSTRAINT workload_metric_samples_resolution_check CHECK (resolution IN (1, 10, 1440))');

        Schema::table('servers', function (Blueprint $table): void {
            $table->string('device_override', 16)->nullable();
            $table->jsonb('location')->nullable();
        });

        DB::statement("ALTER TABLE servers ADD CONSTRAINT servers_device_override_check CHECK (device_override IN ('cloud', 'dedicated', 'tower', 'minipc', 'compact', 'sbc', 'nas', 'laptop', 'vm', 'unknown'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE servers DROP CONSTRAINT IF EXISTS servers_device_override_check');

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['device_override', 'location']);
        });

        Schema::dropIfExists('workload_metric_samples');
        Schema::dropIfExists('server_metric_samples');
    }
};

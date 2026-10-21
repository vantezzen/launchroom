<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Request metrics (docs/15 §1.3): per-minute traffic of each route at each
 * edge, from Report.metrics.requests, attributed to the route's service
 * instance at write time. Counters and the 15-bucket latency histogram are
 * summed by the rollups (same resolutions and retention as the usage
 * metrics), so percentiles stay exact across servers and buckets. The
 * primary key makes every write an idempotent upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_metric_samples', function (Blueprint $table): void {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('route_id', 29);
            $table->smallInteger('resolution');
            $table->timestampTz('at', 0);
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('requests');
            $table->bigInteger('status_2xx')->default(0);
            $table->bigInteger('status_3xx')->default(0);
            $table->bigInteger('status_4xx')->default(0);
            $table->bigInteger('status_5xx')->default(0);
            $table->bigInteger('bytes_in')->default(0);
            $table->bigInteger('bytes_out')->default(0);
            $table->primary(['server_id', 'route_id', 'resolution', 'at']);
            $table->index(['service_instance_id', 'resolution', 'at']);
            $table->index(['project_id', 'resolution', 'at']);
        });

        // Latency histogram: 15 counts over the fixed bucket bounds of docs/15 §1.1.
        DB::statement('ALTER TABLE request_metric_samples ADD COLUMN latency bigint[] NULL');
        DB::statement('ALTER TABLE request_metric_samples ADD CONSTRAINT request_metric_samples_resolution_check CHECK (resolution IN (1, 10, 1440))');
        DB::statement('ALTER TABLE request_metric_samples ADD CONSTRAINT request_metric_samples_latency_check CHECK (latency IS NULL OR cardinality(latency) = 15)');
    }

    public function down(): void
    {
        Schema::dropIfExists('request_metric_samples');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Request analytics (docs/15 §5.3): per route, edge and window, the top
 * normalised paths (`paths`: [{p, r, e4, e5}]) and referrer hosts
 * (`referrers`: [{h, r}]) with everything else summed into the `other_*`
 * columns, plus coarse client classes. 10-minute rows come from
 * Report.metrics.analytics; hourly (60) and daily (1440) rows are rollups.
 * No addresses, query strings or user agents are stored. The primary key
 * makes every write an idempotent upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_analytics_samples', function (Blueprint $table): void {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('route_id', 29);
            $table->smallInteger('resolution');
            $table->timestampTz('at', 0);
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('requests');
            $table->jsonb('paths')->default('[]');
            $table->bigInteger('other_requests')->default(0);
            $table->bigInteger('other_4xx')->default(0);
            $table->bigInteger('other_5xx')->default(0);
            $table->jsonb('referrers')->default('[]');
            $table->bigInteger('other_referrers')->default(0);
            $table->bigInteger('browsers')->default(0);
            $table->bigInteger('bots')->default(0);
            $table->bigInteger('other_agents')->default(0);
            $table->primary(['server_id', 'route_id', 'resolution', 'at']);
            $table->index(['service_instance_id', 'resolution', 'at']);
            $table->index(['project_id', 'resolution', 'at']);
        });

        DB::statement('ALTER TABLE request_analytics_samples ADD CONSTRAINT request_analytics_samples_resolution_check CHECK (resolution IN (10, 60, 1440))');
    }

    public function down(): void
    {
        Schema::dropIfExists('request_analytics_samples');
    }
};

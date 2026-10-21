<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private-network link quality (docs/05 §2.1, docs/07): launchroomd measures
 * latency to each mesh peer every minute and throughput every few hours
 * (Report.host.links).
 *
 * - `server_links`: the latest measurement per direction (server → peer).
 * - `server_link_samples`: hourly rollups per direction (latency sum/min/max
 *   over successful probes, failed probes, the latest throughput in the
 *   hour), kept 30 days (`launchroom:links:prune`). The primary key makes a
 *   measurement repeated by later reports harmless: it is only counted when
 *   its `measuredAt` is newer than the stored one.
 *
 * Servers gain `measure_links` (per-server opt-out, default on) and
 * `daemon_features` (Report.host.features: optional protocol fields its
 * launchroomd understands, docs/03 §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->boolean('measure_links')->default(true);
            $table->jsonb('daemon_features')->nullable();
        });

        Schema::create('server_links', function (Blueprint $table): void {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('peer_server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestampTz('measured_at', 3);
            $table->float('rtt_ms', 24)->nullable();
            $table->string('error', 256)->nullable();
            $table->float('throughput_mbps', 24)->nullable();
            $table->timestampTz('throughput_at', 3)->nullable();
            $table->timestampTz('updated_at', 0);
            $table->primary(['server_id', 'peer_server_id']);
            $table->index('peer_server_id');
        });

        Schema::create('server_link_samples', function (Blueprint $table): void {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('peer_server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestampTz('at', 0);
            $table->integer('samples')->default(0);
            $table->integer('failures')->default(0);
            $table->double('rtt_sum_ms')->default(0);
            $table->float('rtt_min_ms', 24)->nullable();
            $table->float('rtt_max_ms', 24)->nullable();
            $table->float('throughput_mbps', 24)->nullable();
            $table->primary(['server_id', 'peer_server_id', 'at']);
            $table->index('at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_link_samples');
        Schema::dropIfExists('server_links');

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['measure_links', 'daemon_features']);
        });
    }
};

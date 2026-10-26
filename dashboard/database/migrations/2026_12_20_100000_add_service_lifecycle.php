<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service lifecycle (D68, docs/04 §8.1):
 *
 * - `service_instances.stopped_at`/`stopped_by_id`: a stopped service keeps
 *   its configuration, domains, volumes and deployments but runs nothing
 *   (operational state, not part of the revision, so not staged).
 * - `placements.probe`: the health check's progress (attempts, failures,
 *   last error) for the Health step; `placements.issue`: what launchroomd
 *   found clearly wrong (crash loop, port mismatch); `placements.last_exit`:
 *   the last exit (code, out of memory, the last lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->timestamp('stopped_at')->nullable();
            $table->foreignId('stopped_by_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('placements', function (Blueprint $table): void {
            $table->jsonb('probe')->nullable();
            $table->jsonb('issue')->nullable();
            $table->jsonb('last_exit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('placements', fn (Blueprint $table) => $table->dropColumn(['probe', 'issue', 'last_exit']));

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stopped_by_id');
            $table->dropColumn('stopped_at');
        });
    }
};

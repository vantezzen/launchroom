<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-server scheduling (docs/02 §2, §6):
 *
 * - servers.allow_previews / allow_production: per-server placement flags
 *   ("Allow previews", "Allow production"); both on by default.
 * - service_instances.placement_issue: why the instance isn't fully placed
 *   right now (jsonb `{kind, message, serverId?, since}`; kinds
 *   `stateful_server_down`: its volume's server is down and it is never moved
 *   automatically; `unplaceable`: replicas of a dead server found no other
 *   server). Shown as the degraded banner; cleared once resolved.
 * - placements.cpus: the CPU limit reserved by the workload (capacity
 *   accounting next to memory_mb; 0 for rows from before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->boolean('allow_previews')->default(true);
            $table->boolean('allow_production')->default(true);
        });

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->jsonb('placement_issue')->nullable();
        });

        Schema::table('placements', function (Blueprint $table): void {
            $table->decimal('cpus', 8, 3)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('placements', function (Blueprint $table): void {
            $table->dropColumn('cpus');
        });

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropColumn('placement_issue');
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['allow_previews', 'allow_production']);
        });
    }
};

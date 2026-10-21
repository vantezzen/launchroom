<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server health and image retention (docs/03 §1, docs/04 §8):
 *
 * - servers.host_status: the latest host facts and health snapshot from the
 *   agent's reports (normalized, App\Support\Servers\HostHealthFacts);
 *   host_status_at is when the dashboard stored it.
 * - deployments.image_pruned_at: the registry no longer has the deployment's
 *   built image (Zot retention), so it can't be rolled back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->jsonb('host_status')->nullable();
            $table->timestamp('host_status_at')->nullable();
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->timestamp('image_pruned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('image_pruned_at');
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['host_status', 'host_status_at']);
        });
    }
};

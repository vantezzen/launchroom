<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Replace server" and recovery mode (docs/14 §5.2–5.3, §9.14–9.15):
 *
 * - servers.replaced_by_server_id / replaced_at: a dead server replaced by
 *   another (its keys are revoked; it may only fetch its decommission state);
 * - server_replacements: one run of the wizard (inherited roles/labels,
 *   re-placed workloads, restores from the latest backups, progress);
 * - restore_runs.server_replacement_id: restores started by a replacement;
 * - instance_settings.recovery_state: recovery mode after `install --restore`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->foreignId('replaced_by_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();
        });

        Schema::create('server_replacements', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('new_server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('status', 16);
            // Per service: {instance, service, kind, restore{snapshot, takenAt}|null, restoreRun, deployment, status, message}.
            $table->jsonb('services')->default('[]');
            $table->boolean('recovery')->default(false);
            $table->string('error', 2048)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
        DB::statement("ALTER TABLE server_replacements ADD CONSTRAINT server_replacements_status_check CHECK (status IN ('running', 'completed', 'failed'))");
        DB::statement("CREATE UNIQUE INDEX server_replacements_one_per_old ON server_replacements (old_server_id) WHERE status = 'running'");

        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->foreignId('server_replacement_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('instance_settings', function (Blueprint $table): void {
            // {startedAt, previousControlPlaneServerId, replacementId, finishedAt}
            $table->jsonb('recovery_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('recovery_state');
        });
        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('server_replacement_id');
        });
        Schema::dropIfExists('server_replacements');
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaced_by_server_id');
            $table->dropColumn('replaced_at');
        });
    }
};

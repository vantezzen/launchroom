<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard-driven updates (docs/11 §4, docs/07 "Updates"): control-plane
 * updates and rolling launchroomd updates across servers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_plane_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->string('from_version', 64);
            $table->string('to_version', 64);
            // LR_DAEMON_IMAGE of the target release (the daemon checks it against the signed bundle).
            $table->string('daemon_image', 512);
            $table->string('status', 16)->default('pending');
            // Updating without an off-site pre-upgrade backup was acknowledged.
            $table->boolean('skip_backup')->default(false);
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->foreignId('daemon_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 2048)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE control_plane_updates ADD CONSTRAINT control_plane_updates_status_check CHECK (status IN ('pending', 'backing_up', 'updating', 'succeeded', 'failed', 'rolled_back'))");
        // At most one control-plane update in flight.
        DB::statement("CREATE UNIQUE INDEX control_plane_updates_one_active ON control_plane_updates ((true)) WHERE status IN ('pending', 'backing_up', 'updating')");

        Schema::create('fleet_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->string('to_version', 64);
            $table->string('daemon_image', 512);
            $table->string('status', 16)->default('running');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 2048)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE fleet_updates ADD CONSTRAINT fleet_updates_status_check CHECK (status IN ('running', 'succeeded', 'failed'))");
        DB::statement("CREATE UNIQUE INDEX fleet_updates_one_active ON fleet_updates ((true)) WHERE status = 'running'");

        Schema::create('fleet_update_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fleet_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            // Snapshot for display: the server may be renamed or revoked later.
            $table->string('server_name', 100);
            $table->string('from_version', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('daemon_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->string('error', 2048)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['fleet_update_id', 'position']);
        });

        DB::statement("ALTER TABLE fleet_update_steps ADD CONSTRAINT fleet_update_steps_status_check CHECK (status IN ('pending', 'updating', 'succeeded', 'failed', 'rolled_back', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_update_steps');
        Schema::dropIfExists('fleet_updates');
        Schema::dropIfExists('control_plane_updates');
    }
};

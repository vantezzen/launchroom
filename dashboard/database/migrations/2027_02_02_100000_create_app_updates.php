<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App updates for image services (D106, docs/04 §14):
 *
 * - `app_update_checks`: the latest version check of one service instance
 *   (what runs, what is newer, when to check again);
 * - `app_updates`: one update (backup, deploy, watch), at most one open per
 *   instance;
 * - `service_instances.auto_update` and `auto_update_window`: automatic
 *   patch or minor updates inside a maintenance window;
 * - backup runs get the reason `pre_update`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->string('auto_update', 8)->default('off');
            // {days: [1..7], start: "HH:MM", hours: 1..12, timezone: IANA}
            $table->jsonb('auto_update_window')->nullable();
        });

        Schema::create('app_update_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('image', 512)->nullable();
            $table->string('running_digest', 71)->nullable();
            $table->timestamp('running_published_at')->nullable();
            $table->string('status', 16);
            $table->string('reason', 512)->nullable();
            $table->jsonb('candidates')->default('[]');
            $table->string('release_notes_url', 255)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
        });

        Schema::create('app_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->boolean('automatic')->default(false);
            $table->string('kind', 8);
            $table->string('from_reference', 512);
            $table->string('to_reference', 512);
            $table->string('from_version', 128);
            $table->string('to_version', 128);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('backup_run_ids')->default('[]');
            $table->string('backup_note', 512)->nullable();
            $table->string('message', 2048)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['service_instance_id', 'id']);
        });

        DB::statement("ALTER TABLE app_updates ADD CONSTRAINT app_updates_status_check CHECK (status IN ('backing_up', 'deploying', 'watching', 'succeeded', 'failed', 'rolled_back', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX app_updates_one_open ON app_updates (service_instance_id) WHERE status IN ('backing_up', 'deploying', 'watching')");
        DB::statement("ALTER TABLE service_instances ADD CONSTRAINT service_instances_auto_update_check CHECK (auto_update IN ('off', 'patch', 'minor'))");

        DB::statement('ALTER TABLE backup_runs DROP CONSTRAINT IF EXISTS backup_runs_reason_check');
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_reason_check CHECK (reason IN ('scheduled', 'manual', 'pre_upgrade', 'pre_restore', 'pre_delete', 'pre_deploy', 'pre_move', 'pre_update'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backup_runs DROP CONSTRAINT IF EXISTS backup_runs_reason_check');
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_reason_check CHECK (reason IN ('scheduled', 'manual', 'pre_upgrade', 'pre_restore', 'pre_delete', 'pre_deploy', 'pre_move'))");
        DB::statement('ALTER TABLE service_instances DROP CONSTRAINT IF EXISTS service_instances_auto_update_check');
        Schema::dropIfExists('app_updates');
        Schema::dropIfExists('app_update_checks');
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropColumn(['auto_update', 'auto_update_window']);
        });
    }
};

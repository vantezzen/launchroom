<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisions, deployments and placements (docs/07 "Deployments").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // Immutable EnvironmentSpec snapshot; secrets only as ciphertext references.
            $table->jsonb('spec');
            $table->string('message', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['environment_id', 'number']);
        });

        Schema::table('environments', function (Blueprint $table): void {
            $table->foreign('last_applied_revision_id')->references('id')->on('environment_revisions')->nullOnDelete();
        });

        Schema::create('deployments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained('environment_revisions')->nullOnDelete();
            $table->string('trigger', 16);
            $table->string('git_ref')->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->text('commit_message')->nullable();
            $table->string('commit_author')->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('failure_class', 24)->nullable();
            $table->text('failure_message')->nullable();
            // Hash of the build-affecting inputs (docs/04 §7); null for never-built kinds.
            $table->char('build_key', 64)->nullable();
            // Digest-pinned image as rendered into the NodeState (127.0.0.1:5000/…@sha256:…).
            $table->string('image', 512)->nullable();
            $table->jsonb('plan')->nullable();
            $table->foreignId('build_server_id')->nullable()->constrained('servers')->nullOnDelete();
            // Resolved runtime env, encrypted with the project DEK (docs/06 §3.5).
            $table->text('env_snapshot')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignId('rollback_of_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_instance_id', 'status']);
            $table->index(['environment_id', 'created_at']);
        });

        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_status_check CHECK (status IN ('queued', 'building', 'releasing', 'rolling_out', 'health_checking', 'routing', 'active', 'failed', 'cancelled', 'superseded'))");
        // At most one in-flight deployment per service instance (docs/04 §6).
        DB::statement("CREATE UNIQUE INDEX deployments_one_in_flight ON deployments (service_instance_id) WHERE status IN ('building', 'releasing', 'rolling_out', 'health_checking', 'routing')");

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->foreign('active_deployment_id')->references('id')->on('deployments')->nullOnDelete();
        });

        Schema::create('deployment_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 16);
            $table->string('status', 16);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique(['deployment_id', 'name']);
        });

        // One row per workload (deployment × replica slot): desired by the
        // scheduler, observed through agent reports.
        Schema::create('placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->string('workload_id', 40)->unique();
            // Memory limit reserved on the server (scheduler capacity accounting).
            $table->unsignedInteger('memory_mb');
            $table->string('role', 16)->default('serving');
            $table->timestamp('drain_until')->nullable();
            $table->string('phase', 16)->default('pending');
            $table->string('health', 16)->nullable();
            $table->char('container_id', 64)->nullable();
            $table->string('message', 4096)->nullable();
            $table->unsignedInteger('restart_count')->default(0);
            $table->timestamp('observed_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'slot']);
            $table->index(['server_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placements');
        Schema::dropIfExists('deployment_steps');
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropForeign(['active_deployment_id']);
        });
        Schema::dropIfExists('deployments');
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropForeign(['last_applied_revision_id']);
        });
        Schema::dropIfExists('environment_revisions');
    }
};

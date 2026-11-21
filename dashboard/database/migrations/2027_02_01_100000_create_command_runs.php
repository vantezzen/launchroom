<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off commands (D105, docs/03 §7.5): a `run_once` task in the live
 * deployment's image of a service. The row keeps who ran which argv, the
 * outcome and the exit code; the output stays in `log_chunks` of its task
 * and goes with the row (CommandRunRetention). Never an env value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('api_token_id', 30)->nullable();
            $table->foreignId('daemon_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->jsonb('argv');
            $table->string('command_name', 63)->nullable();
            $table->string('status', 16)->default('queued');
            $table->integer('exit_code')->nullable();
            $table->string('error', 2048)->nullable();
            $table->unsignedInteger('timeout_seconds');
            $table->boolean('output_truncated')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_instance_id', 'created_at']);
            $table->index(['service_instance_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE command_runs ADD CONSTRAINT command_runs_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'timed_out', 'cancelled', 'error'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('command_runs');
    }
};

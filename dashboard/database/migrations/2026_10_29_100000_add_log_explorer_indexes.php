<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the project log explorer (EnvironmentLogs, StartLogTail):
 * chunks are found by deployment OR by task (tail and build chunks carry
 * the task), and a tail looks up the live placements of an environment's
 * service instances. The task index also serves the cascade from
 * daemon_tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_chunks', function (Blueprint $table): void {
            $table->index('daemon_task_id');
        });

        Schema::table('placements', function (Blueprint $table): void {
            $table->index('service_instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('log_chunks', function (Blueprint $table): void {
            $table->dropIndex(['daemon_task_id']);
        });

        Schema::table('placements', function (Blueprint $table): void {
            $table->dropIndex(['service_instance_id']);
        });
    }
};

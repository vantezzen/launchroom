<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interactive sessions (Console and Data tabs, docs/03 §11, docs/07):
 * the sessions, the SQL console's per-user history, and per-member grants of
 * the Console/Data permissions (docs/09 R33).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactive_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->string('workload_id', 30);
            $table->unsignedSmallInteger('slot')->default(0);
            $table->string('shell', 32)->nullable();
            $table->string('engine', 16)->nullable();
            $table->char('ticket_hash', 64)->unique();
            $table->timestamp('ticket_expires_at');
            $table->timestamp('ticket_used_at')->nullable();
            $table->text('session_key')->nullable();
            $table->foreignId('daemon_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->boolean('record')->default(false);
            $table->unsignedInteger('idle_seconds');
            $table->unsignedInteger('max_seconds');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_instance_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE interactive_sessions ADD CONSTRAINT interactive_sessions_kind_check CHECK (kind IN ('console', 'data'))");
        DB::statement("ALTER TABLE interactive_sessions ADD CONSTRAINT interactive_sessions_status_check CHECK (status IN ('pending', 'open', 'ended'))");

        Schema::create('database_queries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interactive_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('op_id', 30)->unique();
            $table->text('statement');
            $table->char('statement_hash', 64);
            $table->string('verb', 32);
            $table->boolean('allow_writes')->default(false);
            $table->string('status', 16)->default('sent');
            $table->boolean('truncated')->default(false);
            $table->unsignedInteger('rows')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['service_instance_id', 'user_id', 'created_at']);
        });
        DB::statement("ALTER TABLE database_queries ADD CONSTRAINT database_queries_status_check CHECK (status IN ('sent', 'ok', 'error', 'refused'))");

        Schema::table('team_user', function (Blueprint $table): void {
            $table->jsonb('permissions')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
        Schema::dropIfExists('database_queries');
        Schema::dropIfExists('interactive_sessions');
    }
};

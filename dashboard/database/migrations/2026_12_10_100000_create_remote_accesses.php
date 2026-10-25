<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary remote access (D61, docs/03 §12, docs/09 R39): "Connect via
 * SSH" into a container and temporary database access. The password is
 * encrypted and wiped when the access ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_accesses', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daemon_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->string('workload_id', 30);
            $table->unsignedSmallInteger('slot')->default(0);
            $table->string('engine', 16)->nullable();
            $table->string('username', 32);
            $table->text('password')->nullable();
            $table->jsonb('public_keys')->default('[]');
            $table->jsonb('allow_from');
            $table->jsonb('hosts')->default('[]');
            $table->boolean('allow_writes')->default(false);
            $table->unsignedInteger('idle_seconds');
            $table->unsignedInteger('max_seconds');
            $table->timestamp('expires_at');
            $table->unsignedInteger('port')->nullable();
            $table->jsonb('host_key')->nullable();
            $table->jsonb('certificate')->nullable();
            $table->string('database_name', 63)->nullable();
            $table->jsonb('private_addresses')->default('[]');
            $table->boolean('behind_nat')->default(false);
            $table->unsignedInteger('active_connections')->default(0);
            $table->unsignedInteger('connections')->default(0);
            $table->unsignedInteger('auth_failures')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamp('listening_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 16)->nullable();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_instance_id', 'kind', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['server_id', 'status']);
        });
        DB::statement("ALTER TABLE remote_accesses ADD CONSTRAINT remote_accesses_kind_check CHECK (kind IN ('ssh', 'database'))");
        DB::statement("ALTER TABLE remote_accesses ADD CONSTRAINT remote_accesses_status_check CHECK (status IN ('pending', 'open', 'ended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_accesses');
    }
};

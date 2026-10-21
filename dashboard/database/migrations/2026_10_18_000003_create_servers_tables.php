<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('roles')->default('[]');
            $table->jsonb('labels')->default('{}');
            $table->string('status', 24)->default('pending_approval')->index();
            $table->boolean('is_control_plane')->default(false);
            $table->string('agent_version', 64)->nullable();
            $table->string('protocol_version', 8)->nullable();
            // "ed25519:<base64>" / "x25519:<base64>" (protocol/SIGNING.md §1).
            $table->string('signing_public_key', 64)->unique();
            $table->string('sealing_public_key', 64);
            $table->jsonb('facts')->default('{}');
            $table->string('public_ipv4', 15)->nullable();
            $table->string('public_ipv6', 45)->nullable();
            $table->string('mesh_ip', 45)->nullable();
            $table->unsignedBigInteger('desired_version')->default(0);
            $table->unsignedBigInteger('observed_version')->default(0);
            $table->unsignedBigInteger('last_report_seq')->default(0);
            $table->timestamp('last_report_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        DB::statement("ALTER TABLE servers ADD CONSTRAINT servers_status_check CHECK (status IN ('pending_approval', 'online', 'unreachable', 'offline', 'revoked'))");

        Schema::create('server_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // SHA-256 (hex) of the join token; the token is shown once and never stored.
            $table->char('token_hash', 64)->unique();
            $table->jsonb('roles');
            $table->string('name')->nullable();
            $table->boolean('is_control_plane')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('node_state_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version');
            // The exact JSON bytes sent to the agent (text, not jsonb: the
            // response signature covers these bytes, and jsonb reformats).
            $table->text('document');
            $table->char('document_hash', 64);
            $table->timestamp('created_at');

            $table->unique(['server_id', 'version']);
        });

        Schema::create('agent_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Null until leased, unless the task is pinned to one server.
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('pinned_to_server')->default(false);
            // Which servers may lease an unpinned task.
            $table->string('required_role', 16)->nullable();
            $table->string('type', 32);
            $table->jsonb('spec');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('leased_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(3600);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->unsignedInteger('completed_attempt')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->string('progress', 256)->nullable();
            $table->unsignedBigInteger('deployment_id')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['server_id', 'status']);
        });

        DB::statement("ALTER TABLE agent_tasks ADD CONSTRAINT agent_tasks_status_check CHECK (status IN ('pending', 'leased', 'succeeded', 'failed', 'cancelled'))");

        Schema::create('log_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('deployment_id')->nullable()->index();
            $table->string('source_kind', 16);
            $table->string('source_id', 40);
            $table->unsignedBigInteger('seq');
            $table->jsonb('lines');
            $table->timestamp('created_at');

            // Re-sent chunks are ignored (idempotent shipping).
            $table->unique(['server_id', 'source_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_chunks');
        Schema::dropIfExists('agent_tasks');
        Schema::dropIfExists('node_state_versions');
        Schema::dropIfExists('server_enrollments');
        Schema::dropIfExists('servers');
    }
};

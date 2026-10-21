<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More git providers (docs/19, D46): GitLab, Gitea/Forgejo and plain git
 * servers share connections, a repository cache, ref heads, status reports
 * and PR comments. GitHub keeps its own tables (docs/07 "Git").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('server_kind', 16);
            // Web origin (GitLab/Gitea) or the clone URL (plain git).
            $table->string('base_url', 2048);
            $table->string('name', 100);
            $table->string('account_login', 255)->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('auth_method', 16);
            $table->string('oauth_client_id', 255)->nullable();
            // Envelope-encrypted (docs/09 R13): a per-row DEK wrapped by the
            // KEK, values bound to git_connection|team|id|field.
            $table->text('wrapped_dek');
            $table->string('kek_id', 32);
            $table->text('oauth_client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('webhook_secret');
            $table->text('ssh_private_key')->nullable();
            $table->string('ssh_public_key', 255)->nullable();
            $table->jsonb('host_keys')->nullable();
            $table->timestamp('host_keys_confirmed_at')->nullable();
            $table->boolean('allow_private_network')->default(false);
            $table->string('status', 16)->default('pending');
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('webhook_last_received_at')->nullable();
            $table->timestamp('repositories_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'provider']);
        });

        DB::statement("ALTER TABLE git_connections ADD CONSTRAINT git_connections_provider_check CHECK (provider IN ('gitlab', 'gitea', 'git'))");
        DB::statement("ALTER TABLE git_connections ADD CONSTRAINT git_connections_server_kind_check CHECK (server_kind IN ('gitlab', 'gitea', 'forgejo', 'git'))");
        DB::statement("ALTER TABLE git_connections ADD CONSTRAINT git_connections_auth_method_check CHECK (auth_method IN ('oauth', 'token', 'deploy_key', 'none'))");
        DB::statement("ALTER TABLE git_connections ADD CONSTRAINT git_connections_status_check CHECK (status IN ('pending', 'active', 'needs_reauth'))");
        // One connection per provider account (plain git rows have no account).
        DB::statement('CREATE UNIQUE INDEX git_connections_account_unique ON git_connections (team_id, provider, base_url, account_id) WHERE account_id IS NOT NULL');

        Schema::create('git_repositories', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('git_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_id');
            $table->string('full_name', 255);
            $table->string('default_branch', 255)->nullable();
            $table->boolean('private')->default(true);
            $table->boolean('archived')->default(false);
            $table->timestamp('pushed_at')->nullable();
            $table->string('web_url', 2048)->nullable();
            $table->unsignedBigInteger('hook_id')->nullable();
            $table->string('hook_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['git_connection_id', 'external_id']);
            $table->index(['team_id', 'full_name']);
        });

        Schema::create('git_ref_heads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('git_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_id');
            $table->string('ref', 255);
            $table->char('head_sha', 40)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['git_connection_id', 'external_id', 'ref']);
        });

        Schema::create('git_pull_request_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('git_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_id');
            $table->unsignedInteger('pr_number');
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->jsonb('rows')->default('{}');
            $table->timestamps();

            $table->unique(['git_connection_id', 'external_id', 'pr_number']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->foreignId('git_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['git_connection_id', 'received_at']);
        });

        Schema::table('previews', function (Blueprint $table): void {
            $table->foreignId('git_connection_id')->nullable()->constrained()->nullOnDelete();
        });

        // Single-use OAuth states (team + user bound, PKCE verifier kept here,
        // never sent to the browser).
        Schema::create('git_oauth_states', function (Blueprint $table): void {
            $table->id();
            $table->string('state_hash', 64)->unique();
            $table->foreignId('git_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('code_verifier');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_oauth_states');

        Schema::table('previews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('git_connection_id');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('git_connection_id');
        });

        Schema::dropIfExists('git_pull_request_comments');
        Schema::dropIfExists('git_ref_heads');
        Schema::dropIfExists('git_repositories');
        Schema::dropIfExists('git_connections');
    }
};

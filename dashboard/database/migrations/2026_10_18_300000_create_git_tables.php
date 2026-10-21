<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub integration (docs/07 "Git", docs/09 R17–R20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('app_id');
            $table->string('slug', 100);
            $table->string('name', 100);
            $table->string('owner_login', 100)->nullable();
            $table->string('client_id', 64);
            // Web and API base URLs (github.com or a GitHub Enterprise Server).
            $table->string('web_url', 255);
            $table->string('api_url', 255);
            $table->string('html_url', 255);
            // Envelope-encrypted (docs/09 R13): a per-app data key wrapped by
            // the KEK, values bound to team|app|field as associated data.
            $table->text('wrapped_dek');
            $table->string('kek_id', 32);
            $table->text('client_secret');
            $table->text('webhook_secret')->nullable();
            $table->text('private_key');
            $table->timestamp('webhook_last_received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['api_url', 'app_id']);
            $table->index('app_id');
        });

        Schema::create('github_installations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_app_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('installation_id');
            $table->unsignedBigInteger('account_id');
            $table->string('account_login', 100);
            $table->string('account_type', 32);
            $table->string('repository_selection', 16);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('repositories_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['github_app_id', 'installation_id']);
            $table->index('team_id');
        });

        DB::statement("ALTER TABLE github_installations ADD CONSTRAINT github_installations_selection_check CHECK (repository_selection IN ('all', 'selected'))");

        // Cache of repositories an installation can access. Matching always
        // uses the numeric repository id (docs/09 R18), never the name.
        Schema::create('github_repositories', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_repo_id');
            $table->string('full_name', 200);
            $table->string('default_branch', 255)->nullable();
            $table->boolean('private')->default(true);
            $table->boolean('archived')->default(false);
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['github_installation_id', 'github_repo_id']);
            $table->index(['team_id', 'full_name']);
            $table->index('github_repo_id');
        });

        // Replay protection and debugging. Payloads are never stored: they
        // travel to the processing job encrypted (ShouldBeEncrypted).
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 64)->unique();
            $table->foreignId('github_app_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->string('action', 64)->nullable();
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->unsignedBigInteger('repository_id')->nullable();
            $table->string('status', 16)->default('received');
            $table->string('error', 255)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->index(['github_app_id', 'received_at']);
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('received', 'processed', 'ignored', 'failed'))");

        // Last known head per ref: dedupes webhook and polling pushes and
        // keeps the ETag for conditional polling requests.
        Schema::create('github_ref_heads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_repo_id');
            $table->string('ref', 255);
            $table->char('head_sha', 40)->nullable();
            $table->string('etag', 255)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['github_installation_id', 'github_repo_id', 'ref']);
        });

        // GitHub objects created for a launchroom deployment (Deployments API
        // deployment + check run), so later status changes update them.
        Schema::create('github_deployment_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_repo_id');
            $table->string('deployment_key', 64)->unique();
            $table->unsignedBigInteger('github_deployment_id')->nullable();
            $table->unsignedBigInteger('check_run_id')->nullable();
            $table->timestamps();
        });

        // The single PR comment (a table of all services), updated in place.
        Schema::create('github_pull_request_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_repo_id');
            $table->unsignedInteger('pr_number');
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->jsonb('rows')->default('{}');
            $table->timestamps();

            $table->unique(['github_installation_id', 'github_repo_id', 'pr_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_pull_request_comments');
        Schema::dropIfExists('github_deployment_reports');
        Schema::dropIfExists('github_ref_heads');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('github_repositories');
        Schema::dropIfExists('github_installations');
        Schema::dropIfExists('github_apps');
    }
};

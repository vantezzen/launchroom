<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preview environments per pull request (docs/06 §5, docs/07 "Previews"):
 * per-project preview settings, one row per pull request (its lifecycle,
 * independent of the environment that comes and goes), share links, and the
 * per-service "In previews" clone settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // Null: staging-like persistent environment if one exists, else production.
            $table->foreignId('base_environment_id')->nullable()->constrained('environments')->nullOnDelete();
            // Comma-separated globs of target (base) branches; null = any.
            $table->string('target_branches', 255)->nullable();
            // Only PRs carrying this label get a preview; null = every PR.
            $table->string('required_label', 50)->nullable();
            $table->unsignedSmallInteger('max_concurrent')->default(3);
            $table->unsignedSmallInteger('expiry_days')->default(7);
            $table->string('fork_policy', 16)->default('off');
            $table->boolean('protection')->default(true);
            // Cloning production data into previews needs an admin's opt-in (PII).
            $table->boolean('allow_production_clone')->default(false);
            // CI bypass header: the secret is a MAC under the project DEK over this
            // random nonce (never stored); rotating = a new nonce.
            $table->string('bypass_nonce', 64)->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE preview_settings ADD CONSTRAINT preview_settings_fork_policy_check CHECK (fork_policy IN ('off', 'approval'))");

        Schema::create('previews', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->unique()->constrained()->nullOnDelete();
            // `github` (webhooks) or `git` (local dev simulation of plain git sources).
            $table->string('provider', 16);
            $table->foreignId('github_installation_id')->nullable()->constrained('github_installations')->nullOnDelete();
            // Numeric GitHub repository id (0 for plain git sources).
            $table->unsignedBigInteger('repository_id');
            $table->string('repository_name', 255);
            $table->unsignedInteger('pr_number');
            $table->string('title', 512)->default('');
            $table->string('author_login', 100)->default('');
            $table->string('head_sha', 64);
            $table->string('head_ref', 255);
            $table->string('base_ref', 255);
            $table->boolean('is_fork')->default(false);
            $table->string('author_permission', 16)->nullable();
            $table->jsonb('labels')->default('[]');
            $table->string('status', 24);
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Fork approvals cover one head commit; new commits need a new approval.
            $table->string('approved_sha', 64)->nullable();
            // Per stateful service: data preparation (clone, sanitize) bookkeeping.
            $table->jsonb('data')->default('{}');
            // Changed files of the last synchronize (null: unknown → everything rebuilds).
            $table->jsonb('changed_files')->nullable();
            $table->unsignedBigInteger('github_deployment_id')->nullable();
            $table->string('github_deployment_sha', 64)->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'provider', 'repository_id', 'pr_number']);
            $table->index(['status', 'last_activity_at']);
        });

        Schema::create('preview_share_links', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('preview_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('service_instances', function (Blueprint $table): void {
            // "Clone from another database" (docs/06 §5, docs/14 §6): the source database.
            $table->foreignId('preview_clone_source_id')->nullable()->constrained('service_instances')->nullOnDelete();
            // argv, run in the database image after the restore, before apps start.
            $table->jsonb('preview_sanitize_command')->nullable();
        });

        Schema::table('environments', function (Blueprint $table): void {
            // The pull request's head branch (branch-targeted variables, alias URLs).
            $table->string('pr_head_ref', 255)->nullable();
            // Fork previews get no secrets (docs/09 R20).
            $table->boolean('secrets_withheld')->default(false);
        });

        // Immutable per-deployment URLs `{svc}-{8 chars}.…` (docs/05 §5) look
        // deployments up by the lowercased tail of their ULID.
        DB::statement('CREATE INDEX deployments_url_id ON deployments (lower(right(public_id, 8)))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS deployments_url_id');

        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn(['pr_head_ref', 'secrets_withheld']);
        });
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preview_clone_source_id');
            $table->dropColumn('preview_sanitize_command');
        });
        Schema::dropIfExists('preview_share_links');
        Schema::dropIfExists('previews');
        Schema::dropIfExists('preview_settings');
    }
};

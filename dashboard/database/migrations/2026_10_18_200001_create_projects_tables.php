<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects, environments, services and their per-environment instances
 * (docs/07 "Projects"). Every tenant-owned table carries `team_id` for
 * scoped lookups (docs/09 R7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 63);
            $table->jsonb('settings')->default('{}');
            $table->timestamps();

            $table->unique(['team_id', 'slug']);
        });

        // Wrapped data-encryption keys (docs/09 R13). The newest version encrypts.
        Schema::create('project_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('wrapped_dek');
            $table->string('kek_id', 32);
            $table->timestamps();

            $table->unique(['project_id', 'version']);
        });

        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 63);
            $table->string('kind', 16);
            $table->foreignId('base_environment_id')->nullable()->constrained('environments')->nullOnDelete();
            $table->jsonb('triggers')->default('{}');
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_head_sha', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_deploy_paused')->default(false);
            // FK added with environment_revisions (circular reference).
            $table->unsignedBigInteger('last_applied_revision_id')->nullable();
            // One /24 per environment from launchroom's range (docs/05 §1); unique per install.
            $table->string('network_subnet', 18)->unique();
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });

        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_kind_check CHECK (kind IN ('production', 'persistent', 'preview'))");
        // Exactly one production environment per project (docs/06 §1).
        DB::statement("CREATE UNIQUE INDEX environments_one_production ON environments (project_id) WHERE kind = 'production'");

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // DNS-safe, the network alias and reference name (`${{postgres.X}}`); immutable.
            $table->string('slug', 63);
            $table->string('name');
            $table->string('kind', 16);
            $table->foreignId('parent_service_id')->nullable()->constrained('services')->cascadeOnDelete();
            $table->jsonb('source');
            $table->string('icon', 64)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });

        DB::statement("ALTER TABLE services ADD CONSTRAINT services_kind_check CHECK (kind IN ('app', 'image', 'database', 'worker', 'cron'))");

        Schema::create('service_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('branch_override')->nullable();
            $table->jsonb('build')->default('{}');
            $table->jsonb('deploy')->default('{}');
            $table->jsonb('scaling')->default('{}');
            $table->string('preview_strategy', 16)->nullable();
            // FK added with deployments (circular reference).
            $table->unsignedBigInteger('active_deployment_id')->nullable();
            // Staged removal: excluded from the intent, torn down by the next Deploy.
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['environment_id', 'service_id']);
        });

        Schema::create('connections', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('to_service_id')->constrained('services')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('style', 16);
            // Keys of the connection-owned variables (docs/06 §3.2).
            $table->jsonb('injected')->default('[]');
            $table->timestamps();

            $table->unique(['from_service_id', 'to_service_id']);
        });

        Schema::create('canvas_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('positions')->default('{}');
            $table->timestamps();
        });

        Schema::create('volumes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->string('mount_path', 1024);
            // Pinned at first placement; stateful services never move (docs/02 §6).
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->unique(['service_instance_id', 'mount_path']);
        });

        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            // A hostname belongs to exactly one service instance on the install (docs/05 §5).
            $table->string('hostname', 253)->unique();
            $table->string('kind', 16);
            $table->string('redirect_to', 253)->nullable();
            $table->jsonb('verification')->default('{}');
            $table->string('cert_status', 16)->nullable();
            $table->timestamp('cert_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('instance_settings', function (Blueprint $table): void {
            // Platform wildcard for generated domains, e.g. "apps.example.com" (docs/05 §5).
            $table->string('apps_domain', 253)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('apps_domain');
        });
        Schema::dropIfExists('domains');
        Schema::dropIfExists('volumes');
        Schema::dropIfExists('canvas_layouts');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('service_instances');
        Schema::dropIfExists('services');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('project_keys');
        Schema::dropIfExists('projects');
    }
};

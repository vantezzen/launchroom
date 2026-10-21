<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Environments & promotion (docs/06 §6, docs/07):
 *
 * - environment_promotions: one row per "Stage in target" (kind `promoted`)
 *   and per environment created as a copy of another (kind `created`).
 *   `snapshot` (the source side) and `target_snapshot` (the target after the
 *   promotion) are the three-way bases for the next comparison from → to and
 *   to → from (fingerprints only, never values). `images` pins commits of
 *   promoted images until the target's next Deploy consumes them.
 * - variables.generator: the generator expression of a value that was only a
 *   generator (`secret(32)`), so copies get a fresh value (docs/06 §6.2).
 * - deployments.hold_traffic + status `ready`: staged production deployments
 *   (docs/06 §6.5), built and started but not routed until promoted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_environment_id')->constrained('environments')->cascadeOnDelete();
            $table->foreignId('to_environment_id')->constrained('environments')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('source_revision_id')->nullable()->constrained('environment_revisions')->nullOnDelete();
            $table->jsonb('snapshot');
            $table->jsonb('target_snapshot');
            $table->jsonb('items')->default('[]');
            $table->jsonb('images')->default('{}');
            $table->timestamp('images_applied_at')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['from_environment_id', 'to_environment_id', 'id']);
            $table->index(['to_environment_id', 'id']);
        });

        DB::statement("ALTER TABLE environment_promotions ADD CONSTRAINT environment_promotions_kind_check CHECK (kind IN ('created', 'promoted'))");

        Schema::table('variables', function (Blueprint $table): void {
            $table->string('generator', 64)->nullable();
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->boolean('hold_traffic')->default(false);
        });

        DB::statement('ALTER TABLE deployments DROP CONSTRAINT deployments_status_check');
        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_status_check CHECK (status IN ('queued', 'building', 'releasing', 'rolling_out', 'health_checking', 'ready', 'routing', 'active', 'failed', 'cancelled', 'superseded'))");
        DB::statement('DROP INDEX deployments_one_in_flight');
        DB::statement("CREATE UNIQUE INDEX deployments_one_in_flight ON deployments (service_instance_id) WHERE status IN ('building', 'releasing', 'rolling_out', 'health_checking', 'ready', 'routing')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX deployments_one_in_flight');
        DB::statement("CREATE UNIQUE INDEX deployments_one_in_flight ON deployments (service_instance_id) WHERE status IN ('building', 'releasing', 'rolling_out', 'health_checking', 'routing')");
        DB::statement('ALTER TABLE deployments DROP CONSTRAINT deployments_status_check');
        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_status_check CHECK (status IN ('queued', 'building', 'releasing', 'rolling_out', 'health_checking', 'routing', 'active', 'failed', 'cancelled', 'superseded'))");

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('hold_traffic');
        });

        Schema::table('variables', function (Blueprint $table): void {
            $table->dropColumn('generator');
        });

        Schema::dropIfExists('environment_promotions');
    }
};

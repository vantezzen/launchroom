<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security center (D63, docs/22 §8):
 *
 * - inventories of live images per team (`image_inventories`, components in
 *   `inventory_components`), linked from deployments;
 * - public advisory data, instance-wide: OSV records (`advisories`), the
 *   query cache (`advisory_queries`), CISA KEV (`known_exploited`),
 *   endoflife.date (`lifecycle_releases`) and feed state (`advisory_feeds`,
 *   one row per source with its on/off switch);
 * - findings per service instance and advisory group, investigations with
 *   their encrypted container inspections;
 * - the web firewall: per-instance mode and paranoia (existing instances
 *   start in Log only, new ones in Block), exclusions, 10-minute and hourly
 *   samples, event samples with the client's /24 or /48 network only, and
 *   each edge's reported firewall state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_inventories', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('image', 512);
            $table->string('platform', 32);
            $table->string('source', 16);
            $table->string('status', 16)->default('pending');
            $table->foreignId('task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            // The service whose build the source analysis used (apps/ images).
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('generator', 512)->nullable();
            $table->boolean('source_analyzed')->default(false);
            $table->jsonb('distro')->nullable();
            $table->jsonb('lockfiles')->default('[]');
            $table->jsonb('notes')->default('[]');
            $table->integer('component_count')->default(0);
            $table->integer('unqueried_count')->default(0);
            $table->integer('dropped')->default(0);
            $table->boolean('truncated')->default(false);
            $table->string('error', 1024)->nullable();
            $table->smallInteger('retries')->default(0);
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['team_id', 'image', 'platform']);
            $table->index(['status', 'failed_at']);
        });

        Schema::create('inventory_components', function (Blueprint $table): void {
            $table->foreignId('inventory_id')->constrained('image_inventories')->cascadeOnDelete();
            $table->string('ecosystem', 16);
            $table->string('name', 256);
            $table->string('version', 128);
            $table->string('purl', 1024)->nullable();
            $table->string('kind', 16);
            $table->string('scope', 16);
            $table->string('presence', 16);
            $table->boolean('direct')->default(false);
            $table->string('osv_ecosystem', 64)->nullable();
            $table->string('osv_name', 256)->nullable();
            $table->string('osv_version', 128)->nullable();
            $table->primary(['inventory_id', 'ecosystem', 'name', 'version']);
            $table->index(['osv_ecosystem', 'osv_name', 'osv_version']);
        });

        DB::statement('CREATE INDEX inventory_components_name_trgm ON inventory_components USING gin (lower(name) gin_trgm_ops)');

        Schema::table('deployments', function (Blueprint $table): void {
            $table->foreignId('inventory_id')->nullable()->constrained('image_inventories')->nullOnDelete();
        });

        Schema::create('advisories', function (Blueprint $table): void {
            $table->string('id', 128)->primary();
            $table->string('group_key', 128)->index();
            $table->jsonb('aliases')->default('[]');
            $table->jsonb('upstream')->default('[]');
            $table->jsonb('cve_ids')->default('[]');
            $table->timestampTz('modified')->nullable();
            $table->timestampTz('published')->nullable();
            $table->timestampTz('withdrawn')->nullable();
            $table->string('summary', 512)->nullable();
            $table->text('details')->nullable();
            $table->string('severity_label', 16)->nullable();
            $table->jsonb('cvss')->default('{}');
            $table->string('attack_vector', 1)->nullable();
            $table->jsonb('cwe_ids')->default('[]');
            $table->jsonb('affected')->default('[]');
            $table->jsonb('references')->default('[]');
            $table->timestampTz('fetched_at');
            $table->timestampTz('matched_at')->nullable();
        });

        Schema::create('advisory_queries', function (Blueprint $table): void {
            $table->string('osv_ecosystem', 64);
            $table->string('osv_name', 256);
            $table->string('version', 128);
            $table->jsonb('advisory_ids')->default('[]');
            // Newest `modified` per id as querybatch reported it (re-hydrate when newer).
            $table->jsonb('modified')->default('{}');
            $table->timestampTz('checked_at')->nullable();
            $table->string('error', 512)->nullable();
            $table->primary(['osv_ecosystem', 'osv_name', 'version']);
            $table->index('checked_at');
        });

        Schema::create('known_exploited', function (Blueprint $table): void {
            $table->string('cve_id', 32)->primary();
            $table->date('date_added');
            $table->date('due_date')->nullable();
            $table->boolean('ransomware')->default(false);
            $table->string('vendor', 128)->nullable();
            $table->string('product', 128)->nullable();
            $table->string('name', 256)->nullable();
        });

        Schema::create('lifecycle_releases', function (Blueprint $table): void {
            $table->string('product', 32);
            $table->string('cycle', 32);
            $table->string('latest', 64)->nullable();
            $table->date('latest_date')->nullable();
            $table->date('release_date')->nullable();
            $table->date('eol')->nullable();
            $table->boolean('is_eol')->default(false);
            $table->timestampTz('fetched_at');
            $table->primary(['product', 'cycle']);
        });

        Schema::create('advisory_feeds', function (Blueprint $table): void {
            $table->string('feed', 8)->primary();
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('failing_since')->nullable();
            $table->string('last_error', 512)->nullable();
            $table->string('etag', 256)->nullable();
            $table->string('last_modified', 64)->nullable();
            $table->integer('items')->default(0);
            $table->jsonb('cursor')->nullable();
            $table->integer('backoff_seconds')->default(0);
            $table->timestampTz('retry_at')->nullable();
            $table->timestampTz('manual_check_at')->nullable();
            $table->timestampsTz();
        });

        $now = now();
        DB::table('advisory_feeds')->insert(array_map(static fn (string $feed): array => ['feed' => $feed, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now], ['osv', 'kev', 'eol']));

        Schema::create('security_investigations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('finding_id')->nullable();
            $table->string('status', 16)->default('running');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('automatic')->default(false);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            // One entry per replica: {workload, server, task, state, message} (docs/22 §9.4).
            $table->jsonb('replicas')->default('[]');
            // ContainerInspectionResults, encrypted under the project DEK.
            $table->text('inspections')->nullable();
            $table->jsonb('report')->nullable();
            $table->timestampsTz();
            $table->index(['service_instance_id', 'started_at']);
        });

        Schema::create('security_findings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_id')->nullable()->constrained('image_inventories')->nullOnDelete();
            $table->string('group_key', 128);
            $table->jsonb('advisory_ids')->default('[]');
            $table->string('ecosystem', 16);
            $table->string('name', 256);
            $table->string('version', 128);
            $table->string('kind', 16);
            $table->string('scope', 16);
            $table->string('presence', 16);
            $table->boolean('direct')->default(false);
            $table->string('level', 16);
            $table->jsonb('reasons')->default('[]');
            // The evaluated rules A1–A6 as a checklist (docs/22 §9.3).
            $table->jsonb('checks')->default('[]');
            $table->string('attack_vector', 1)->nullable();
            $table->string('severity', 16)->default('unknown');
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('fixed_version', 128)->nullable();
            $table->boolean('known_exploited')->default(false);
            $table->date('known_exploited_since')->nullable();
            $table->boolean('public')->default(false);
            $table->string('status', 16)->default('open');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('level_changed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dismiss_reason', 16)->nullable();
            $table->string('dismiss_note', 500)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->foreignId('investigation_id')->nullable()->constrained('security_investigations')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['team_id', 'status', 'level']);
            $table->index(['service_instance_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX security_findings_one_live ON security_findings (service_instance_id, group_key, ecosystem, name, version) WHERE status <> 'resolved'");

        Schema::table('security_investigations', function (Blueprint $table): void {
            $table->foreign('finding_id')->references('id')->on('security_findings')->nullOnDelete();
        });

        Schema::table('service_instances', function (Blueprint $table): void {
            // Existing instances start in Log only (docs/22 §6.2) ...
            $table->string('waf_mode', 8)->default('log');
            $table->smallInteger('waf_paranoia')->default(1);
            $table->timestampTz('waf_mode_changed_at')->nullable();
        });

        // ... and every instance created from now on in Block.
        DB::statement("ALTER TABLE service_instances ALTER COLUMN waf_mode SET DEFAULT 'block'");
        DB::statement("ALTER TABLE service_instances ADD CONSTRAINT service_instances_waf_mode_check CHECK (waf_mode IN ('off', 'log', 'block'))");
        DB::statement('ALTER TABLE service_instances ADD CONSTRAINT service_instances_waf_paranoia_check CHECK (waf_paranoia IN (1, 2))');

        Schema::create('waf_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->integer('rule_id');
            $table->string('path_prefix', 128)->nullable();
            $table->string('target', 96)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index('service_instance_id');
        });

        DB::statement("CREATE UNIQUE INDEX waf_exclusions_unique ON waf_exclusions (service_instance_id, rule_id, COALESCE(path_prefix, ''), COALESCE(target, ''))");

        Schema::create('waf_samples', function (Blueprint $table): void {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('route_id', 29);
            $table->smallInteger('resolution');
            $table->timestampTz('at', 0);
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('blocked')->default(0);
            $table->bigInteger('detected')->default(0);
            // [{c, b, d}] per category, [{r, b, d}] top rules, [{p, b, d}] top paths.
            $table->jsonb('categories')->default('[]');
            $table->jsonb('rules')->default('[]');
            $table->jsonb('paths')->default('[]');
            $table->bigInteger('other_paths_blocked')->default(0);
            $table->bigInteger('other_paths_detected')->default(0);
            $table->primary(['server_id', 'route_id', 'resolution', 'at']);
            $table->index(['service_instance_id', 'resolution', 'at']);
            $table->index(['project_id', 'resolution', 'at']);
        });

        DB::statement('ALTER TABLE waf_samples ADD CONSTRAINT waf_samples_resolution_check CHECK (resolution IN (10, 60))');

        Schema::create('waf_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('route_id', 29);
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('at', 3);
            $table->string('action', 8);
            $table->string('method', 16);
            $table->string('path', 128);
            $table->jsonb('rule_ids')->default('[]');
            $table->jsonb('categories')->default('[]');
            $table->jsonb('targets')->default('[]');
            $table->string('client_network', 43);
            $table->integer('score')->default(0);
            $table->string('reference', 128);
            $table->unique(['server_id', 'reference', 'at']);
            $table->index(['service_instance_id', 'at']);
            $table->index(['project_id', 'at']);
        });

        DB::statement("ALTER TABLE waf_events ADD CONSTRAINT waf_events_action_check CHECK (action IN ('blocked', 'detected'))");

        Schema::table('servers', function (Blueprint $table): void {
            // Latest Report.ingress.waf (docs/03 §13.4) plus when its state began.
            $table->jsonb('waf_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('waf_status'));
        Schema::dropIfExists('waf_events');
        Schema::dropIfExists('waf_samples');
        Schema::dropIfExists('waf_exclusions');
        DB::statement('ALTER TABLE service_instances DROP CONSTRAINT IF EXISTS service_instances_waf_mode_check');
        DB::statement('ALTER TABLE service_instances DROP CONSTRAINT IF EXISTS service_instances_waf_paranoia_check');
        Schema::table('service_instances', fn (Blueprint $table) => $table->dropColumn(['waf_mode', 'waf_paranoia', 'waf_mode_changed_at']));
        Schema::table('security_investigations', fn (Blueprint $table) => $table->dropForeign(['finding_id']));
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_investigations');
        Schema::dropIfExists('advisory_feeds');
        Schema::dropIfExists('lifecycle_releases');
        Schema::dropIfExists('known_exploited');
        Schema::dropIfExists('advisory_queries');
        Schema::dropIfExists('advisories');
        Schema::table('deployments', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_id'));
        Schema::dropIfExists('inventory_components');
        Schema::dropIfExists('image_inventories');
    }
};

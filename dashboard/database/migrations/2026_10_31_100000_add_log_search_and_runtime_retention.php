<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-service log search and opt-in runtime log retention (docs/08 §12,
 * docs/07 "Logs"). Lines stay in `log_chunks` (one row per shipped batch);
 * benchmarked against a separate line table, chunks plus a trigram index
 * over their text kept the storage small and searches fast
 * (docs/notes/2026-10-log-search-benchmark.md):
 *
 * - every chunk carries its project, environment and service (resolved
 *   from its deployment at ingest), whether it is runtime output, and its
 *   line, error and byte counts, so filters and histograms work on chunks;
 * - each stored line carries its guessed `level` (App\Support\Logs\LogLevel);
 * - `lr_log_text(lines)` (the lines' text joined by newlines) has a GIN
 *   trigram index (pg_trgm, a trusted extension) for substring search;
 *   covering (scope, created_at) indexes answer histograms index-only.
 *
 * Environments get the runtime log retention setting: off (null) or a
 * number of days, plus a size cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION lr_log_text(lines jsonb) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT string_agg(e->>'text', E'\n') FROM jsonb_array_elements(lines) e $$
            SQL);

        Schema::table('log_chunks', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('runtime')->default(false);
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('bytes')->default(0);
        });

        // Backfill: scope from the deployment, runtime from the source, the
        // level of every line (the PHP heuristic ported to Postgres regexes;
        // new lines get it from LogLevel at ingest), counts.
        DB::statement(<<<'SQL'
            UPDATE log_chunks c
            SET project_id = s.project_id, environment_id = d.environment_id, service_id = s.id
            FROM deployments d
            JOIN service_instances si ON si.id = d.service_instance_id
            JOIN services s ON s.id = si.service_id
            WHERE c.deployment_id = d.id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE log_chunks SET runtime = true
            WHERE source_kind = 'workload'
               OR daemon_task_id IN (SELECT id FROM daemon_tasks WHERE type = 'logs')
            SQL);
        DB::statement(<<<'SQL_WRAP'
        CREATE FUNCTION pg_temp.lr_guess_level(t text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
          SELECT CASE
            WHEN p ~* '\m(error|fatal|panic|exception|traceback|failed)\M|err!|ERR_|SQLSTATE|exit code: [1-9]' THEN 'error'
            WHEN p ~* '\m(warn|warning|deprecated)\M' THEN 'warning'
            WHEN p ~* '^\s*(\[?debug\]?|dbg)\M|\mlevel=debug\M' THEN 'debug'
            ELSE 'info' END
          FROM (SELECT regexp_replace(t, E'\\x1b\\[[0-9;]*m', '', 'g') AS p) s
        $$
        SQL_WRAP);
        DB::statement(<<<'SQL'
            UPDATE log_chunks SET lines = (
              SELECT coalesce(jsonb_agg(e || jsonb_build_object('level', pg_temp.lr_guess_level(coalesce(e->>'text', ''))) ORDER BY ord), '[]'::jsonb)
              FROM jsonb_array_elements(lines) WITH ORDINALITY a(e, ord))
            SQL);
        DB::statement(<<<'SQL'
            UPDATE log_chunks SET line_count = jsonb_array_length(lines), bytes = octet_length(lines::text),
              error_count = (SELECT count(*) FROM jsonb_array_elements(lines) e WHERE e->>'level' = 'error')
            SQL);
        DB::statement('DROP FUNCTION pg_temp.lr_guess_level(text)');

        foreach (['team_id', 'project_id', 'environment_id'] as $scope) {
            $index = "log_chunks_{$scope}_created_at_index";
            DB::statement("CREATE INDEX {$index} ON log_chunks ({$scope}, created_at) INCLUDE (line_count, error_count, service_id, runtime)");
        }

        DB::statement('CREATE INDEX log_chunks_text_trgm ON log_chunks USING gin (lr_log_text(lines) gin_trgm_ops)');
        // Pruning runtime lines by age (PruneRuntimeLogs).
        DB::statement('CREATE INDEX log_chunks_runtime_created_at_index ON log_chunks (created_at) WHERE runtime');

        Schema::table('environments', function (Blueprint $table): void {
            // Runtime log retention (opt-in): null = off.
            $table->unsignedSmallInteger('runtime_log_retention_days')->nullable();
            $table->unsignedInteger('runtime_log_max_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn(['runtime_log_retention_days', 'runtime_log_max_mb']);
        });

        DB::statement('DROP INDEX IF EXISTS log_chunks_text_trgm');
        DB::statement('DROP INDEX IF EXISTS log_chunks_runtime_created_at_index');

        foreach (['team_id', 'project_id', 'environment_id'] as $scope) {
            DB::statement("DROP INDEX IF EXISTS log_chunks_{$scope}_created_at_index");
        }

        Schema::table('log_chunks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('environment_id');
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['runtime', 'line_count', 'error_count', 'bytes']);
        });

        DB::statement('DROP FUNCTION IF EXISTS lr_log_text(jsonb)');
        // Lines keep their `level` key: harmless for older code.
    }
};

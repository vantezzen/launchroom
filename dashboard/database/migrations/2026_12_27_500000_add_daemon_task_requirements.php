<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a server must offer to lease a task, decided when the task is created
 * (from its TaskSpec, in DaemonTasks::create) instead of read back from its spec
 * by the lease query: the launchroomd features its spec needs and the
 * platform it runs on (builds may use an emulated platform, D53). Log tails
 * name their user in `requested_by_id` instead of in `purpose`.
 *
 * Unfinished tasks get the requirements the lease query used to infer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daemon_tasks', function (Blueprint $table): void {
            $table->jsonb('required_features')->default('[]');
            $table->string('required_platform', 32)->nullable();
            $table->boolean('platform_emulation')->default(false);
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE daemon_tasks SET
                required_platform = CASE WHEN type IN ('build', 'plan', 'inventory') THEN spec->>'platform' END,
                platform_emulation = type IN ('build', 'plan'),
                required_features = (
                    SELECT COALESCE(jsonb_agg(feature), '[]'::jsonb) FROM (VALUES
                        (CASE WHEN type IN ('build', 'plan') AND ((spec->'repo'->>'provider') IN ('gitlab', 'gitea') OR (spec->'repo'->>'cloneUrl') LIKE 'ssh://%' OR (spec->'repo'->>'ref') IS NOT NULL) THEN 'gitProviders' END),
                        (CASE WHEN (spec->'builder'->>'dockerfile') IS NOT NULL OR COALESCE(spec->>'inspect', '') = 'true' OR (spec->'probe') IS NOT NULL THEN 'generatedDockerfile' END),
                        (CASE WHEN (spec->'builder'->'phpExtensions') IS NOT NULL THEN 'phpExtensions' END),
                        (CASE WHEN type = 'inventory' THEN 'inventory' END),
                        (CASE WHEN type = 'inspect_container' THEN 'containerInspection' END)
                    ) AS required(feature) WHERE feature IS NOT NULL
                )
            WHERE status IN ('pending', 'leased')
            SQL);

        DB::statement(<<<'SQL'
            UPDATE daemon_tasks SET
                requested_by_id = (SELECT id FROM users WHERE users.id = substring(daemon_tasks.purpose FROM 6)::bigint),
                purpose = 'log_tail'
            WHERE purpose ~ '^tail:[0-9]{1,18}$'
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE daemon_tasks SET purpose = CASE WHEN requested_by_id IS NULL THEN NULL ELSE 'tail:' || requested_by_id END
            WHERE purpose = 'log_tail'
            SQL);

        Schema::table('daemon_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_id');
            $table->dropColumn(['required_features', 'required_platform', 'platform_emulation']);
        });
    }
};

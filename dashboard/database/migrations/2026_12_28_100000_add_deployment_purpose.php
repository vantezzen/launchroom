<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a deployment is for (App\Enums\DeploymentPurpose), so the pipeline
 * branches on a column instead of markers in `plan`: `plan.prebuildFor`
 * becomes purpose `prebuild` + `target_platform`, `plan.prepareImport`
 * becomes purpose `prepare_import` + `prepared_until`. The markers are
 * removed from `plan`, which keeps only what the build produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('release');
            $table->string('target_platform', 32)->nullable();
            $table->timestamp('prepared_until')->nullable();
            $table->index(['purpose', 'status']);
        });

        DB::statement(<<<'SQL'
            UPDATE deployments
            SET purpose = 'prebuild',
                target_platform = plan->>'prebuildFor',
                plan = NULLIF(plan - 'prebuildFor', '{}'::jsonb)
            WHERE plan->>'prebuildFor' IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE deployments
            SET purpose = 'prepare_import',
                prepared_until = CASE WHEN jsonb_typeof(plan->'prepareImport'->'until') = 'string' THEN (plan->'prepareImport'->>'until')::timestamptz AT TIME ZONE 'UTC' END,
                plan = NULLIF(plan - 'prepareImport', '{}'::jsonb)
            WHERE plan->'prepareImport' IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE deployments
            SET plan = COALESCE(plan, '{}'::jsonb) || jsonb_build_object('prebuildFor', target_platform)
            WHERE purpose = 'prebuild' AND target_platform IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE deployments
            SET plan = COALESCE(plan, '{}'::jsonb) || jsonb_build_object('prepareImport', jsonb_build_object('until', prepared_until))
            WHERE purpose = 'prepare_import'
            SQL);

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex(['purpose', 'status']);
            $table->dropColumn(['purpose', 'target_platform', 'prepared_until']);
        });
    }
};

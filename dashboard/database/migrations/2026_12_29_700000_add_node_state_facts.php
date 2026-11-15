<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `node_state_versions.facts` (App\Data\Servers\NodeStateFacts): the declared
 * networks, workloads and images and the mesh allow-list of each version, recorded at
 * render so readers (mesh readiness, peer invalidation, holds) stop
 * re-parsing the signed document. Existing versions are backfilled from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_state_versions', function (Blueprint $table): void {
            $table->jsonb('facts')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE node_state_versions AS v
            SET facts = jsonb_build_object(
                'networks', COALESCE((SELECT jsonb_agg(n->'id') FROM jsonb_array_elements(CASE WHEN jsonb_typeof(d.doc->'networks') = 'array' THEN d.doc->'networks' ELSE '[]'::jsonb END) AS n WHERE jsonb_typeof(n->'id') = 'string'), '[]'::jsonb),
                'workloads', COALESCE((SELECT jsonb_agg(w->'id') FROM jsonb_array_elements(CASE WHEN jsonb_typeof(d.doc->'workloads') = 'array' THEN d.doc->'workloads' ELSE '[]'::jsonb END) AS w WHERE jsonb_typeof(w->'id') = 'string'), '[]'::jsonb),
                'images', COALESCE((SELECT jsonb_agg(DISTINCT w->'image') FROM jsonb_array_elements(CASE WHEN jsonb_typeof(d.doc->'workloads') = 'array' THEN d.doc->'workloads' ELSE '[]'::jsonb END) AS w WHERE jsonb_typeof(w->'image') = 'string'), '[]'::jsonb),
                'meshAllow', COALESCE((SELECT jsonb_object_agg(r->>'port', r->'from') FROM jsonb_array_elements(CASE WHEN jsonb_typeof(d.doc->'firewall'->'meshAllow') = 'array' THEN d.doc->'firewall'->'meshAllow' ELSE '[]'::jsonb END) AS r WHERE jsonb_typeof(r->'port') = 'number' AND jsonb_typeof(r->'from') = 'array'), '{}'::jsonb)
            )
            FROM (SELECT id, document::jsonb AS doc FROM node_state_versions) AS d
            WHERE d.id = v.id
            SQL);

        // A version without facts would read as declaring no workloads, which
        // lets a hold proceed while its workload may still run: never allow it.
        DB::statement('ALTER TABLE node_state_versions ALTER COLUMN facts SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('node_state_versions', function (Blueprint $table): void {
            $table->dropColumn('facts');
        });
    }
};

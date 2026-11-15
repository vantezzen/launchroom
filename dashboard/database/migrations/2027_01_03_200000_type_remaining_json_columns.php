<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The remaining object-shaped JSON columns are cast to Data objects
 * (docs/10 §2.2 rule 2). Every stored shape already has their keys except
 * one: an import that succeeded without a DataImportResult stored `[]`,
 * which DataImportResult can't read. It becomes null ("no result").
 *
 * The keys are frozen here as they were when this migration was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("UPDATE data_import_runs SET result = NULL WHERE result IS NOT NULL AND NOT jsonb_exists_all(result, array['mode', 'empty', 'durationMs'])");
    }

    public function down(): void
    {
        // Lossless enough: a null result reads as "nothing imported" either way.
    }
};

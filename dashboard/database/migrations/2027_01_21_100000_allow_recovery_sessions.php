<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recovery shells (OD9) are interactive sessions of kind `recovery`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE interactive_sessions DROP CONSTRAINT interactive_sessions_kind_check');
        DB::statement("ALTER TABLE interactive_sessions ADD CONSTRAINT interactive_sessions_kind_check CHECK (kind IN ('console', 'data', 'recovery'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM interactive_sessions WHERE kind = 'recovery'");
        DB::statement('ALTER TABLE interactive_sessions DROP CONSTRAINT interactive_sessions_kind_check');
        DB::statement("ALTER TABLE interactive_sessions ADD CONSTRAINT interactive_sessions_kind_check CHECK (kind IN ('console', 'data'))");
    }
};

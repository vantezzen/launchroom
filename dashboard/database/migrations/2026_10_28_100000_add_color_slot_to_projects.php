<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project's slot in the categorical data palette (docs/design/direction.md
 * §4, §8.4): assigned at creation and kept, so a project has the same colour
 * in every chart. Existing projects get slots per team in creation order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->smallInteger('color_slot')->default(0);
        });

        DB::statement(<<<'SQL'
            UPDATE projects SET color_slot = ranked.slot
            FROM (SELECT id, ((row_number() OVER (PARTITION BY team_id ORDER BY id)) - 1) % 8 AS slot FROM projects) AS ranked
            WHERE projects.id = ranked.id
            SQL);

        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_color_slot_check CHECK (color_slot BETWEEN 0 AND 7)');
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('color_slot');
        });
    }
};

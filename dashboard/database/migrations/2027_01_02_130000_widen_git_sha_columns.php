<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commit ids may be SHA-256 (64 hex, git's SHA-256 object format) as well as
 * SHA-1 (docs/19 §2); these columns held 40 characters only.
 */
return new class extends Migration
{
    private const array COLUMNS = [
        'github_ref_heads' => 'head_sha',
        'git_ref_heads' => 'head_sha',
        'dockerfile_generations' => 'commit_sha',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $table) use ($column): void {
                $table->string($column, 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('github_ref_heads', function (Blueprint $table): void {
            $table->char('head_sha', 40)->nullable()->change();
        });
        Schema::table('git_ref_heads', function (Blueprint $table): void {
            $table->char('head_sha', 40)->nullable()->change();
        });
        Schema::table('dockerfile_generations', function (Blueprint $table): void {
            $table->string('commit_sha', 40)->nullable()->change();
        });
    }
};

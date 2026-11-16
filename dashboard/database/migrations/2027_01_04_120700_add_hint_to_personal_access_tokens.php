<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A token's non-secret hint (`lr_pat_…a1b2`: the prefix and the last four
 * characters of its plaintext), stored at creation so people can match a
 * row in Settings → API tokens to the string in their CI (docs/16 §1.3).
 * Tokens created before this have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('hint', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('hint');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-run checklist progress (docs/08 §3.1): which steps the owner skipped
 * and whether the checklist was dismissed. The steps themselves are derived
 * from real state (server online, GitHub connected, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->jsonb('onboarding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('onboarding');
        });
    }
};

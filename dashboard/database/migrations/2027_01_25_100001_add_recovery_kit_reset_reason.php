<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a saved recovery kit must be saved again (docs/14 §4): the passphrase
 * or launchroom's own backup storage changed. Cleared when the kit is
 * confirmed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('recovery_kit_reset_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('recovery_kit_reset_reason');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a workload's observed phase last changed (D108): the age of a
 * service's "Not running" or "Unhealthy". `observed_at` moves with every
 * report, so it can't say how long a phase has lasted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placements', function (Blueprint $table): void {
            $table->timestamp('phase_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('placements', function (Blueprint $table): void {
            $table->dropColumn('phase_changed_at');
        });
    }
};

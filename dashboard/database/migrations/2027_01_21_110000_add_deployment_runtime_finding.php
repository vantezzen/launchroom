<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deployments.runtime_finding` (G12): the first known problem launchroom
 * saw in a live deployment's runtime logs (a framework refusing its domain,
 * CSRF origins, a worker timeout), with the fix in words
 * (App\Actions\Logs\DetectRuntimeFindings). It shows in the service panel
 * and in "Needs you" while that deployment is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->jsonb('runtime_finding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('runtime_finding');
        });
    }
};

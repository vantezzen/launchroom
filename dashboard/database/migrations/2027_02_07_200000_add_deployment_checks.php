<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deploy after checks pass (D111, docs/06 §2.5): a push deployment of an
 * environment that waits for its commit's CI checks keeps the gate here
 * (`DeploymentChecksData`); null when it didn't wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->jsonb('checks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('checks');
        });
    }
};

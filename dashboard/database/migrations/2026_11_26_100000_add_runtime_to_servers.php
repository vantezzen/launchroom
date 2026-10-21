<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * servers.runtime: how Docker runs on the server (rootful/rootless), whether
 * launchroomd reaches it through dockerd-guard, and what it can do there
 * (Report.host.runtime, D49). Null until the server reported it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->jsonb('runtime')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('runtime');
        });
    }
};

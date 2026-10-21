<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image pull progress per placement (docs/04 §11 "Pulls"): what launchroomd
 * reports in `Report.workloads[].pull` while a workload is `pulling`
 * ({stage, downloaded, total} in bytes), null otherwise. The deployment's
 * health step shows it ("Downloading image (412 MB of 690 MB)") and its
 * deadline waits while the pull moves (`pull_seen_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placements', function (Blueprint $table): void {
            $table->jsonb('pull')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('placements', function (Blueprint $table): void {
            $table->dropColumn('pull');
        });
    }
};

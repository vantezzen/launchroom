<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probation after go-live and automatic rollback (docs/04 §8, OD1):
 *
 * - `deployments.probation_until`: a production deployment of a new version
 *   is watched this long after it went live; a crash loop or every replica
 *   down rolls it back once (App\Actions\Deployments\CheckProbation).
 * - `deployments.rollback_reason`: why launchroom rolled back by itself, on
 *   the rollback deployment it created; null for manual rollbacks.
 * - `deployments.image_facts`: the image's user and exposed ports
 *   (protocol ImageFacts) for images launchroom didn't build, as the server
 *   reported them after the pull; built images carry them in the build
 *   summary.
 * - `service_instances.last_auto_rollback_at`: at most one automatic
 *   rollback per service and hour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->timestamp('probation_until')->nullable();
            $table->string('rollback_reason', 1000)->nullable();
            $table->jsonb('image_facts')->nullable();
            $table->index('probation_until');
        });

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->timestamp('last_auto_rollback_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex(['probation_until']);
            $table->dropColumn(['probation_until', 'rollback_reason', 'image_facts']);
        });

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropColumn('last_auto_rollback_at');
        });
    }
};

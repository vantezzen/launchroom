<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Builds, release commands and plan previews (docs/04 §12–13, docs/03 §7):
 *
 * - agent_tasks.purpose: what a task is for beyond its type (`release`,
 *   `seed` for run_once tasks).
 * - agent_tasks.seal: references to the secrets a task needs (which
 *   deployment env keys, which clone credentials). Specs are stored as
 *   templates; the secrets are sealed to the leasing server only at lease
 *   time and never stored (docs/03 §7 "Sealing at lease time").
 * - service_instances.plan: the latest plan preview ("we detected…").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table): void {
            $table->string('purpose', 16)->nullable();
            $table->jsonb('seal')->nullable();
        });

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->jsonb('plan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropColumn('plan');
        });

        Schema::table('agent_tasks', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'seal']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres doesn't index foreign keys by itself. These four are read on
 * every canvas and service panel render (CurrentEnvironmentSpec,
 * CanvasGraph, ServiceControlsData): eager loads by `service_instance_id`
 * and `variable_id`, the dependents count by `to_service_id`, and
 * `whereHas('instances')` by `service_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', fn (Blueprint $table) => $table->index('service_instance_id'));
        Schema::table('variable_targets', fn (Blueprint $table) => $table->index('variable_id'));
        Schema::table('connections', fn (Blueprint $table) => $table->index('to_service_id'));
        Schema::table('service_instances', fn (Blueprint $table) => $table->index('service_id'));
    }

    public function down(): void
    {
        Schema::table('service_instances', fn (Blueprint $table) => $table->dropIndex(['service_id']));
        Schema::table('connections', fn (Blueprint $table) => $table->dropIndex(['to_service_id']));
        Schema::table('variable_targets', fn (Blueprint $table) => $table->dropIndex(['variable_id']));
        Schema::table('domains', fn (Blueprint $table) => $table->dropIndex(['service_instance_id']));
    }
};

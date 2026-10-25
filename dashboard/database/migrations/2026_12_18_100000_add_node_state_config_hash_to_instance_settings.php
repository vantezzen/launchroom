<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fingerprint of the configuration that feeds every NodeState (system
 * image pins, Caddy modules, the dashboard version; RefreshNodeStates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('node_state_config_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('node_state_config_hash');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The web firewall blocks by default for every service instance (docs/22
 * §6.2, D63). v0.1.0-rc.8 started existing instances in Log only; those
 * nobody has switched since (`waf_mode_changed_at` is null) move to Block.
 * Instances without routes keep the setting without effect, like new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_instances')
            ->where('waf_mode', 'log')
            ->whereNull('waf_mode_changed_at')
            ->update(['waf_mode' => 'block']);
    }

    public function down(): void
    {
        // Not reversible: the instances that started in Log only aren't recorded.
    }
};

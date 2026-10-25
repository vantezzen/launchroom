<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a request body the web firewall inspects per service
 * instance: 32 KiB (default), 128 KiB or 1 MiB (docs/22 §6.3, §6.7;
 * `RouteWaf.inspectBodyKiB`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->smallInteger('waf_inspect_body_kib')->default(32);
        });

        DB::statement('ALTER TABLE service_instances ADD CONSTRAINT service_instances_waf_inspect_body_check CHECK (waf_inspect_body_kib IN (32, 128, 1024))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_instances DROP CONSTRAINT IF EXISTS service_instances_waf_inspect_body_check');
        Schema::table('service_instances', fn (Blueprint $table) => $table->dropColumn('waf_inspect_body_kib'));
    }
};

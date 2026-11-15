<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cause (App\Enums\Alerts\AlertKind) and the measured number (a
 * percentage) of an alert, so chips stop parsing titles and facts. Firing
 * alerts are refreshed every evaluation, so existing rows fill themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->string('kind', 32)->nullable();
            $table->double('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'value']);
        });
    }
};

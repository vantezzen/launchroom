<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_metric_samples', function (Blueprint $table): void {
            $table->double('build_cpu_cores')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_metric_samples', function (Blueprint $table): void {
            $table->dropColumn('build_cpu_cores');
        });
    }
};

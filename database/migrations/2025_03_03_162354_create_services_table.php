<?php

use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->foreignIdFor(Environment::class)->index()->constrained()->cascadeOnDelete();
            $table->string('category')->description('The category of service (e.g. database, cache, etc.)');
            $table->string('service_type')->description('The service type (e.g. MySQL, Redis, etc.)');
            $table->jsonb('environment_variables');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};

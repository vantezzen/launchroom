<?php

use App\Models\Project;
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
            $table->string('id')->primary();

            $table->string('name');
            $table->foreignIdFor(Project::class)->index();
            $table->string('service_type');
            $table->json('environment_variables');
            $table->json('environment_types')->comment('Type of environments this service should be attached to (production, staging, testing, development)');
            
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

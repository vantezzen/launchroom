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
        Schema::create('environments', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->foreignHashIdFor(Project::class)->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('branch')->default('main');
            $table->enum('type', ['production', 'staging', 'testing', 'development']);
            $table->jsonb('domains');
            $table->jsonb('environment_variables');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};

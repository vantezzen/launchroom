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
        Schema::create('deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignIdFor(Environment::class)->index()->constrained()->cascadeOnDelete();
            $table->string('commit_hash');
            $table->enum('status', ['pending', 'deploying', 'failed', 'succeeded']);
            $table->text('output')->nullable();
            $table->boolean('is_latest')->default(true);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};

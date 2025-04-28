<?php

use App\Models\Team;
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
        Schema::create('encryption_keys', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->foreignHashIdFor(Team::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->text('public_key');
            $table->text('private_key');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('encryption_keys');
    }
};

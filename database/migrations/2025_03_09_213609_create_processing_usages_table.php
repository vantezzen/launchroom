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
        Schema::create('processing_usages', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->foreignHashIdFor(Environment::class)
                ->index()
                ->constrained()
                ->cascadeOnDelete();
            $table->float('cpu');
            $table->float('mem_usage');
            $table->float('mem_limit');
            $table->float('mem_percent');
            $table->float('net_in');
            $table->float('net_out');
            $table->float('block_in');
            $table->float('block_out');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processing_usages');
    }
};

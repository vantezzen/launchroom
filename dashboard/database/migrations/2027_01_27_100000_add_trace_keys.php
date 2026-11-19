<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request trace keys (docs/23 §4): one AES-256 key per edge server at a
 * time, envelope-encrypted (docs/09 R13). The id is the key id the trace
 * header names: a random uint32 chosen before the row is sealed (the
 * ciphertext is bound to it). Rotated every 30 days (`retired_at`), deleted
 * 90 days after creation (App\Actions\Traces\ProvisionTraceKeys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_keys', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->text('ciphertext');
            $table->text('wrapped_dek');
            $table->string('kek_id', 64);
            $table->timestamp('created_at');
            $table->timestamp('retired_at')->nullable();

            $table->index(['server_id', 'retired_at']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_keys');
    }
};

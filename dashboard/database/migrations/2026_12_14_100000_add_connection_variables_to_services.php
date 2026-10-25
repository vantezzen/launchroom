<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a connection to a service injects into the consumer, when the
 * service says so itself (D65, docs/06 §3.2): Supabase's gateway provides
 * its URL and API keys. A list of {key, value}; values reference the
 * service's own variables (`${{ANON_KEY}}`). Null: the default naming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->jsonb('connection_variables')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('connection_variables');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the server first asked to join a session's channel (docs/03 §11.2),
 * so a session that never started can tell which side didn't arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactive_sessions', function (Blueprint $table): void {
            $table->timestamp('server_joined_at')->nullable()->after('ticket_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('interactive_sessions', function (Blueprint $table): void {
            $table->dropColumn('server_joined_at');
        });
    }
};

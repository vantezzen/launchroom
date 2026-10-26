<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook health from missed pushes (docs/06 §2.2): `awaiting_webhook_since`
 * marks a head that polling found before any webhook delivered it. When no
 * webhook follows within the grace period, the app or connection records
 * `webhook_missed_at` and its tracked refs are polled every minute until the
 * next delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['github_ref_heads', 'git_ref_heads'] as $heads) {
            Schema::table($heads, function (Blueprint $table): void {
                $table->timestamp('awaiting_webhook_since')->nullable();
            });
        }

        foreach (['github_apps', 'git_connections'] as $owners) {
            Schema::table($owners, function (Blueprint $table): void {
                $table->timestamp('webhook_missed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['github_ref_heads', 'git_ref_heads'] as $heads) {
            Schema::table($heads, fn (Blueprint $table) => $table->dropColumn('awaiting_webhook_since'));
        }

        foreach (['github_apps', 'git_connections'] as $owners) {
            Schema::table($owners, fn (Blueprint $table) => $table->dropColumn('webhook_missed_at'));
        }
    }
};
